<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\PublicFormResource;
use App\Models\Activity;
use App\Models\Form;
use App\Models\FormSubmission;
use App\Models\Lead;
use App\Models\User;
use App\Models\Workspace;
use App\Notifications\NewLeadReceived;
use App\Services\AutomationService;
use App\Services\PlanService;
use App\Support\CurrentWorkspace;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Public, unauthenticated form endpoints: rate limited, server side validated,
 * honeypot + timing trap spam protection and origin checks.
 */
class PublicFormController extends Controller
{
    /** Hidden field that humans never fill in. */
    public const HONEYPOT = 'website';

    /** Submissions faster than this after rendering are treated as bots. */
    public const MIN_SECONDS = 2;

    public function __construct(protected PlanService $plans, protected AutomationService $automations, protected CurrentWorkspace $current) {}

    public function show(string $slug): JsonResponse
    {
        $form = $this->findPublished($slug);

        if (! $this->plans->hasFeature($form->workspace, 'remove_branding')) {
            $form->show_branding = true;
        }

        return response()->json([
            'data' => PublicFormResource::make($form),
            'token' => Crypt::encryptString((string) now()->getTimestamp()),
            'honeypot' => self::HONEYPOT,
        ]);
    }

    public function submit(Request $request, string $slug): JsonResponse
    {
        $form = $this->findPublished($slug);
        $workspace = $form->workspace;
        $this->ensureOriginAllowed($request, $form);

        $thanks = ['message' => $form->thank_you_message ?: trans('crm.form_thank_you', [], $form->locale)];

        if ($this->looksLikeSpam($request)) {
            $this->storeSubmission($request, $form, $workspace, [], 'spam');

            return response()->json($thanks, 201);
        }

        $limit = $this->plans->limit($workspace, 'submissions_per_month');

        if ($limit !== null && $this->plans->usageFor($workspace, 'submissions_per_month') >= $limit) {
            return response()->json(['message' => trans('crm.form_not_available', [], $form->locale), 'code' => 'submission_limit'], 429);
        }

        $values = $this->validateFields($request, $form);

        DB::transaction(function () use ($request, $form, $workspace, $values): void {
            $this->current->set($workspace);
            $lead = $this->findOrCreateLead($form, $workspace, $values, $request);
            $submission = $this->storeSubmission($request, $form, $workspace, $values, $lead ? 'accepted' : 'over_limit', $lead);
            $form->increment('submissions_count');

            if ($lead) {
                Activity::create([
                    'workspace_id' => $workspace->id,
                    'subject_type' => $lead->getMorphClass(),
                    'subject_id' => $lead->id,
                    'type' => 'form_submission',
                    'title' => trans('crm.activity.form_submission', ['form' => $form->name], $workspace->locale),
                    'body' => $values['message'] ?? null,
                    'meta' => ['submission_id' => $submission->id, 'form_id' => $form->id],
                    'occurred_at' => now(),
                ]);

                DB::afterCommit(fn () => $this->notify($form, $workspace, $lead));
            }
        });

        $this->current->clear();

        return response()->json($thanks, 201);
    }

    protected function findPublished(string $slug): Form
    {
        $form = Form::withoutGlobalScopes()->with('workspace')->where('slug', $slug)->whereNull('deleted_at')->first();

        if (! $form || ! $form->isPublished() || $form->workspace->suspended_at !== null) {
            abort(response()->json(['message' => __('crm.form_not_available'), 'code' => 'form_not_available'], 404));
        }

        return $form;
    }

    protected function ensureOriginAllowed(Request $request, Form $form): void
    {
        $origin = $request->headers->get('Origin');

        if (! $origin) {
            return;
        }

        $allowed = array_map(fn ($url) => rtrim(Str::lower($url), '/'), array_merge([config('app.frontend_url')], $form->allowed_origins ?? []));

        if (! in_array(rtrim(Str::lower($origin), '/'), $allowed, true)) {
            abort(response()->json(['message' => __('crm.form_not_available'), 'code' => 'origin_not_allowed'], 403));
        }
    }

    protected function looksLikeSpam(Request $request): bool
    {
        if (filled($request->input(self::HONEYPOT))) {
            return true;
        }

        try {
            $renderedAt = (int) Crypt::decryptString((string) $request->input('_token'));
        } catch (DecryptException) {
            return true;
        }

        $elapsed = now()->getTimestamp() - $renderedAt;

        return $elapsed < self::MIN_SECONDS || $elapsed > 60 * 60 * 24;
    }

    /**
     * @return array<string, mixed>
     */
    protected function validateFields(Request $request, Form $form): array
    {
        $rules = [];
        $attributes = [];

        foreach ($form->fields as $field) {
            $key = 'data.'.$field['key'];
            $attributes[$key] = $field['label'];
            $rule = [! empty($field['required']) ? 'required' : 'nullable'];

            $rule = array_merge($rule, match ($field['type']) {
                'email' => ['email:rfc', 'max:255'],
                'tel' => ['string', 'max:50', 'regex:/^[0-9+()\/\-\s.]{5,30}$/'],
                'number' => ['numeric'],
                'date' => ['date'],
                'checkbox' => ['boolean'],
                'select' => ['string', 'in:'.implode(',', array_map(fn ($option) => str_replace(',', '\,', $option), $field['options'] ?? []))],
                'textarea' => ['string', 'max:5000'],
                default => ['string', 'max:255'],
            });

            $rules[$key] = $rule;
        }

        $rules['marketing_consent'] = ['nullable', 'boolean'];
        $rules['privacy_accepted'] = $form->privacy_url ? ['accepted'] : ['nullable', 'boolean'];
        $rules['utm'] = ['nullable', 'array'];
        $rules['utm.*'] = ['nullable', 'string', 'max:255'];
        $rules['referrer'] = ['nullable', 'string', 'max:500'];

        app()->setLocale($form->locale);
        $validated = validator($request->all(), $rules, ['privacy_accepted.accepted' => trans('crm.privacy_required', [], $form->locale)], $attributes)->validate();

        $values = $validated['data'] ?? [];

        if (blank($values['email'] ?? null) && blank($values['phone'] ?? null)) {
            throw ValidationException::withMessages(['data.email' => trans('validation.required', ['attribute' => 'e-mail'])]);
        }

        return $values;
    }

    /**
     * @param  array<string, mixed>  $values
     */
    protected function findOrCreateLead(Form $form, Workspace $workspace, array $values, Request $request): ?Lead
    {
        $email = filled($values['email'] ?? null) ? Str::lower($values['email']) : null;

        $existing = Lead::query()
            ->whereNull('converted_at')
            ->when($email, fn ($q) => $q->whereRaw('lower(email) = ?', [$email]), fn ($q) => $q->where('phone', $values['phone']))
            ->latest()
            ->first();

        if ($existing) {
            if ($existing->status === 'unqualified') {
                $existing->update(['status' => 'new']);
            }

            return $existing;
        }

        if ($this->plans->remaining($workspace, 'active_leads') === 0) {
            return null;
        }

        $utm = $this->plans->hasFeature($workspace, 'utm_tracking') ? $this->utm($request) : [];
        $name = $values['name'] ?? trim(($values['last_name'] ?? '').' '.($values['first_name'] ?? '')) ?: ($email ?? $values['phone']);

        $lead = Lead::create(array_merge([
            'workspace_id' => $workspace->id,
            'name' => Str::limit($name, 250, ''),
            'company' => $values['company'] ?? null,
            'email' => $email,
            'phone' => $values['phone'] ?? null,
            'message' => $values['message'] ?? null,
            'status' => 'new',
            'source' => $request->boolean('via_link') ? 'link' : 'form',
            'form_id' => $form->id,
            'owner_id' => $form->default_owner_id ?? $workspace->owner_id,
        ], $utm));

        $this->automations->fire($workspace, 'lead_created', $lead);

        return $lead;
    }

    /**
     * @param  array<string, mixed>  $values
     */
    protected function storeSubmission(Request $request, Form $form, Workspace $workspace, array $values, string $status, ?Lead $lead = null): FormSubmission
    {
        $utm = $this->plans->hasFeature($workspace, 'utm_tracking') ? $this->utm($request) : [];

        return FormSubmission::create(array_merge([
            'workspace_id' => $workspace->id,
            'form_id' => $form->id,
            'lead_id' => $lead?->id,
            'data' => $values,
            'status' => $status,
            'ip_hash' => hash('sha256', $request->ip().'|'.config('app.key')),
            'user_agent' => Str::limit((string) $request->userAgent(), 490, ''),
            'marketing_consent' => $request->boolean('marketing_consent'),
            'privacy_accepted' => $request->boolean('privacy_accepted'),
            'privacy_version' => $form->privacy_version,
        ], $utm));
    }

    /**
     * @return array<string, string|null>
     */
    protected function utm(Request $request): array
    {
        $utm = (array) $request->input('utm', []);

        return [
            'utm_source' => isset($utm['source']) ? Str::limit((string) $utm['source'], 250, '') : null,
            'utm_medium' => isset($utm['medium']) ? Str::limit((string) $utm['medium'], 250, '') : null,
            'utm_campaign' => isset($utm['campaign']) ? Str::limit((string) $utm['campaign'], 250, '') : null,
            'utm_content' => isset($utm['content']) ? Str::limit((string) $utm['content'], 250, '') : null,
            'referrer' => $request->filled('referrer') ? Str::limit((string) $request->input('referrer'), 490, '') : null,
        ];
    }

    protected function notify(Form $form, Workspace $workspace, Lead $lead): void
    {
        $notification = (new NewLeadReceived($lead, $form))->locale($workspace->locale);

        if ($form->notify_owner) {
            $owner = User::find($lead->owner_id ?? $workspace->owner_id);
            $owner?->notify($notification);
        }

        foreach ($form->notify_emails ?? [] as $email) {
            Notification::route('mail', $email)->notify($notification);
        }
    }
}
