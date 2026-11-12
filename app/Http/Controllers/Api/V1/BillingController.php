<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\Plan;
use App\Http\Controllers\Controller;
use App\Models\Invoice;
use App\Services\AuditLogger;
use App\Services\Billing\BillingGateway;
use App\Services\Billing\BillingService;
use App\Services\PlanService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Laravel\Cashier\Exceptions\IncompletePayment;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Subscription management for workspace owners/admins: billing details, Stripe Checkout,
 * customer portal, plan/interval change, cancellation and the issued invoices.
 */
class BillingController extends Controller
{
    public function __construct(
        protected BillingService $billing,
        protected BillingGateway $gateway,
        protected PlanService $plans,
        protected AuditLogger $audit,
    ) {}

    public function show(): JsonResponse
    {
        $workspace = $this->workspace();

        return response()->json([
            'status' => $this->billing->status($workspace),
            'details' => $this->details(),
            'invoices' => $workspace->invoices()->limit(50)->get()->map(fn (Invoice $invoice) => $this->invoicePayload($invoice)),
            'vat_key' => config('billing.vat.key'),
            'prices_include_vat' => (bool) config('billing.vat.prices_include_vat'),
        ]);
    }

    public function updateDetails(Request $request): JsonResponse
    {
        $workspace = $this->workspace();
        $data = $request->validate([
            'billing_name' => ['required', 'string', 'max:255'],
            'billing_email' => ['required', 'email', 'max:255'],
            'billing_country' => ['required', 'string', 'size:2'],
            'billing_postcode' => ['required', 'string', 'max:20'],
            'billing_city' => ['required', 'string', 'max:255'],
            'billing_address' => ['required', 'string', 'max:255'],
            'billing_tax_number' => ['nullable', 'string', 'max:30', Rule::when(strtoupper((string) $request->input('billing_country')) === 'HU', ['regex:/^\d{8}-?\d-?\d{2}$/'])],
            'billing_eu_vat_number' => ['nullable', 'string', 'max:30', 'regex:/^[A-Za-z]{2}[0-9A-Za-z+*.]{2,13}$/'],
        ]);
        $data['billing_country'] = strtoupper($data['billing_country']);

        $workspace->update($data);
        $this->gateway->syncCustomer($workspace);
        $this->audit->log('billing.details_updated', $workspace);

        return response()->json(['details' => $this->details(), 'status' => $this->billing->status($workspace)]);
    }

    /**
     * Starts Stripe Checkout. Subscribing during the trial keeps the remaining free days.
     */
    public function checkout(Request $request): JsonResponse
    {
        $workspace = $this->workspace();
        [$plan, $interval] = $this->validatedPlan($request);

        $this->ensureConfigured();

        if ($this->billing->subscription($workspace)?->valid()) {
            throw ValidationException::withMessages(['plan' => __('billing.already_subscribed')]);
        }

        if (config('billing.require_billing_details') && ! $workspace->hasCompleteBillingDetails()) {
            throw ValidationException::withMessages(['billing' => __('billing.billing_details_required')]);
        }

        $base = rtrim(config('app.frontend_url'), '/').'/settings/subscription';
        $url = $this->gateway->checkoutUrl(
            $workspace,
            $this->billing->priceId($plan, $interval),
            $workspace->onGenericTrial() ? $workspace->trial_ends_at : null,
            $base.'?checkout=success',
            $base.'?checkout=cancelled',
        );

        $workspace->forceFill(['intended_plan' => $plan->value])->save();
        $this->audit->log('billing.checkout_started', $workspace, ['plan' => $plan->value, 'interval' => $interval]);

        return response()->json(['url' => $url]);
    }

    public function portal(): JsonResponse
    {
        $this->ensureConfigured();
        $url = $this->gateway->portalUrl($this->workspace(), rtrim(config('app.frontend_url'), '/').'/settings/subscription');

        return response()->json(['url' => $url]);
    }

    /**
     * Upgrades/downgrades between Pro and Business or switches monthly/yearly (prorated by Stripe).
     * Data over the new limits is kept, read-only and exportable.
     */
    public function swap(Request $request): JsonResponse
    {
        $workspace = $this->workspace();
        [$plan, $interval] = $this->validatedPlan($request);
        $this->ensureSubscribed();

        try {
            $this->gateway->swap($workspace, $this->billing->priceId($plan, $interval));
        } catch (IncompletePayment) {
            return response()->json(['message' => __('billing.payment_failed'), 'code' => 'payment_failed'], 402);
        }

        $this->billing->sync($workspace);
        $this->audit->log('billing.swapped', $workspace, ['plan' => $plan->value, 'interval' => $interval]);

        return $this->statusResponse();
    }

    public function cancel(): JsonResponse
    {
        $this->ensureSubscribed();
        $this->gateway->cancel($this->workspace());
        $this->billing->sync($this->workspace());
        $this->audit->log('billing.cancelled', $this->workspace());

        return $this->statusResponse();
    }

    public function resume(): JsonResponse
    {
        $subscription = $this->billing->subscription($this->workspace());

        if (! $subscription?->onGracePeriod()) {
            throw ValidationException::withMessages(['subscription' => __('billing.no_subscription')]);
        }

        $this->gateway->resume($this->workspace());
        $this->billing->sync($this->workspace());
        $this->audit->log('billing.resumed', $this->workspace());

        return $this->statusResponse();
    }

    public function downloadInvoice(Invoice $invoice): StreamedResponse
    {
        abort_unless($invoice->workspace_id === $this->workspace()->id && $invoice->pdf_path, 404);

        return Storage::disk('local')->download($invoice->pdf_path, $invoice->number.'.pdf');
    }

    /**
     * @return array{0: Plan, 1: string}
     */
    protected function validatedPlan(Request $request): array
    {
        $data = $request->validate([
            'plan' => ['required', Rule::in([Plan::Pro->value, Plan::Business->value])],
            'interval' => ['required', Rule::in(config('billing.intervals'))],
        ]);

        $plan = Plan::from($data['plan']);

        if (blank($this->billing->priceId($plan, $data['interval']))) {
            throw ValidationException::withMessages(['plan' => __('billing.not_configured')]);
        }

        return [$plan, $data['interval']];
    }

    protected function ensureConfigured(): void
    {
        if (! $this->billing->isConfigured()) {
            abort(response()->json(['message' => __('billing.not_configured'), 'code' => 'billing_not_configured'], 503));
        }
    }

    protected function ensureSubscribed(): void
    {
        if (! $this->billing->subscription($this->workspace())?->valid()) {
            throw ValidationException::withMessages(['subscription' => __('billing.no_subscription')]);
        }
    }

    protected function statusResponse(): JsonResponse
    {
        $workspace = $this->workspace()->refresh();

        return response()->json(['status' => $this->billing->status($workspace), 'subscription' => $this->plans->summary($workspace)]);
    }

    /**
     * @return array<string, string|null>
     */
    protected function details(): array
    {
        return $this->workspace()->only([
            'billing_name', 'billing_email', 'billing_country', 'billing_postcode', 'billing_city', 'billing_address', 'billing_tax_number', 'billing_eu_vat_number',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    protected function invoicePayload(Invoice $invoice): array
    {
        return [
            'id' => $invoice->id,
            'number' => $invoice->number,
            'status' => $invoice->status,
            'plan' => $invoice->plan,
            'interval' => $invoice->interval,
            'currency' => $invoice->currency,
            'net_amount' => (float) $invoice->net_amount,
            'vat_amount' => (float) $invoice->vat_amount,
            'gross_amount' => (float) $invoice->gross_amount,
            'period_start' => $invoice->period_start?->toDateString(),
            'period_end' => $invoice->period_end?->toDateString(),
            'paid_at' => $invoice->paid_at,
            'has_pdf' => filled($invoice->pdf_path),
        ];
    }
}
