<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\FormResource;
use App\Http\Resources\FormSubmissionResource;
use App\Models\Form;
use App\Services\AuditLogger;
use App\Services\PlanService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class FormController extends Controller
{
    public function __construct(protected PlanService $plans, protected AuditLogger $audit) {}

    public function index(): AnonymousResourceCollection
    {
        return FormResource::collection(Form::latest()->get());
    }

    public function store(Request $request): JsonResponse
    {
        $this->plans->ensureWithinLimit($this->workspace(), 'forms');
        $data = $this->validated($request);
        $data['fields'] ??= Form::defaultFields();
        $data['slug'] = $this->uniqueSlug($data['name']);
        $data['locale'] ??= $this->workspace()->locale;
        $data['privacy_version'] ??= $data['privacy_url'] ?? null ? now()->format('Y-m-d') : null;

        $form = Form::create($data);
        $this->audit->log('form.created', $form, ['name' => $form->name]);

        return FormResource::make($form)->response()->setStatusCode(201);
    }

    public function show(Form $form): FormResource
    {
        return FormResource::make($form);
    }

    public function update(Request $request, Form $form): FormResource
    {
        $data = $this->validated($request, true);

        /* A new privacy notice URL means a new version that is stored with every later submission. */
        if (array_key_exists('privacy_url', $data) && $data['privacy_url'] !== $form->privacy_url && ! isset($data['privacy_version'])) {
            $data['privacy_version'] = $data['privacy_url'] ? now()->format('Y-m-d H:i') : null;
        }

        $form->update($data);

        if (isset($data['status'])) {
            $this->audit->log('form.'.$data['status'], $form);
        }

        return FormResource::make($form);
    }

    public function destroy(Form $form): JsonResponse
    {
        $form->delete();
        $this->audit->log('form.deleted', $form, ['name' => $form->name]);

        return response()->json(['ok' => true]);
    }

    public function submissions(Request $request, Form $form): AnonymousResourceCollection
    {
        $query = $form->submissions()->latest();
        $query->when($request->query('status'), fn ($q, $status) => $q->where('status', $status));

        return FormSubmissionResource::collection($query->paginate($this->perPage($request)));
    }

    /**
     * @return array<string, mixed>
     */
    protected function validated(Request $request, bool $updating = false): array
    {
        $required = $updating ? 'sometimes' : 'required';

        $data = $request->validate([
            'name' => [$required, 'string', 'max:255'],
            'status' => ['sometimes', Rule::in(['draft', 'published', 'archived'])],
            'locale' => ['sometimes', Rule::in(['hu', 'en', 'de'])],
            'title' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'fields' => ['sometimes', 'array', 'min:1', 'max:30'],
            'fields.*.key' => ['required', 'string', 'max:50', 'regex:/^[a-z][a-z0-9_]*$/', 'distinct'],
            'fields.*.label' => ['required', 'string', 'max:255'],
            'fields.*.type' => ['required', Rule::in(Form::FIELD_TYPES)],
            'fields.*.required' => ['sometimes', 'boolean'],
            'fields.*.placeholder' => ['nullable', 'string', 'max:255'],
            'fields.*.options' => ['nullable', 'array', 'max:50'],
            'fields.*.options.*' => ['string', 'max:255'],
            'thank_you_message' => ['nullable', 'string', 'max:2000'],
            'privacy_url' => ['nullable', 'url:https,http', 'max:500'],
            'privacy_version' => ['nullable', 'string', 'max:30'],
            'ask_marketing_consent' => ['sometimes', 'boolean'],
            'notify_owner' => ['sometimes', 'boolean'],
            'notify_emails' => ['nullable', 'array', 'max:5'],
            'notify_emails.*' => ['email'],
            'show_branding' => ['sometimes', 'boolean'],
            'allowed_origins' => ['nullable', 'array', 'max:10'],
            'allowed_origins.*' => ['url:https,http'],
            'default_owner_id' => ['nullable', 'integer', $this->memberRule()],
        ]);

        if (isset($data['fields'])) {
            $keys = array_column($data['fields'], 'key');
            $custom = array_diff($keys, Form::BASE_FIELDS);

            if ($custom !== [] && ! $this->plans->hasFeature($this->workspace(), 'custom_form_fields')) {
                throw ValidationException::withMessages(['fields' => __('crm.feature_not_available')]);
            }

            if (! in_array('email', $keys, true) && ! in_array('phone', $keys, true)) {
                throw ValidationException::withMessages(['fields' => 'The form needs an e-mail or phone field.']);
            }
        }

        if (($data['show_branding'] ?? true) === false && ! $this->plans->hasFeature($this->workspace(), 'remove_branding')) {
            $data['show_branding'] = true;
        }

        return $data;
    }

    protected function uniqueSlug(string $name): string
    {
        do {
            $slug = Str::limit(Str::slug($name) ?: 'form', 40, '').'-'.Str::lower(Str::random(6));
        } while (Form::withoutGlobalScopes()->withTrashed()->where('slug', $slug)->exists());

        return $slug;
    }
}
