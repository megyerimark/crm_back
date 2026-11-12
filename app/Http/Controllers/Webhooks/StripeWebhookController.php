<?php

namespace App\Http\Controllers\Webhooks;

use App\Jobs\IssueInvoice;
use App\Models\Invoice;
use App\Models\Workspace;
use App\Services\Billing\BillingService;
use App\Services\Billing\InvoiceCalculator;
use Carbon\Carbon;
use Laravel\Cashier\Cashier;
use Laravel\Cashier\Http\Controllers\WebhookController;
use Symfony\Component\HttpFoundation\Response;

/**
 * Cashier's webhook handling (signature verification, subscription records) plus:
 * syncing the workspace plan after every subscription change and issuing the
 * Hungarian invoice when Stripe reports a paid invoice.
 */
class StripeWebhookController extends WebhookController
{
    protected function handleCustomerSubscriptionCreated(array $payload): Response
    {
        $response = parent::handleCustomerSubscriptionCreated($payload);
        $this->syncWorkspace($payload);

        return $response;
    }

    protected function handleCustomerSubscriptionUpdated(array $payload): Response
    {
        $response = parent::handleCustomerSubscriptionUpdated($payload) ?? $this->successMethod();
        $this->syncWorkspace($payload);

        return $response;
    }

    protected function handleCustomerSubscriptionDeleted(array $payload): Response
    {
        $response = parent::handleCustomerSubscriptionDeleted($payload);
        $this->syncWorkspace($payload);

        return $response;
    }

    /**
     * invoice.paid: record the payment and queue the Számlázz.hu e-invoice (zero amount trial invoices are skipped).
     */
    protected function handleInvoicePaid(array $payload): Response
    {
        $data = $payload['data']['object'];
        $workspace = Cashier::findBillable($data['customer'] ?? null);

        if (! $workspace instanceof Workspace || (int) ($data['amount_paid'] ?? 0) <= 0) {
            return $this->successMethod();
        }

        $billing = app(BillingService::class);
        $line = collect($data['lines']['data'] ?? [])->first(fn ($line) => filled($this->linePrice($line))) ?? ($data['lines']['data'][0] ?? []);
        $mapped = $billing->planForPrice($this->linePrice($line));
        $currency = strtoupper($data['currency'] ?? 'HUF');
        $vatKey = (string) config('billing.vat.key');
        $amounts = app(InvoiceCalculator::class)->split(
            $this->toMajorUnits((int) $data['amount_paid'], $currency),
            $vatKey,
            (bool) config('billing.vat.prices_include_vat'),
            $currency,
        );

        $invoice = Invoice::firstOrCreate(['stripe_invoice_id' => $data['id']], [
            'workspace_id' => $workspace->id,
            'stripe_number' => $data['number'] ?? null,
            'plan' => $mapped['plan']->value ?? $workspace->plan,
            'interval' => $mapped['interval'] ?? $workspace->billing_interval,
            'currency' => $currency,
            'net_amount' => $amounts['net'],
            'vat_amount' => $amounts['vat'],
            'gross_amount' => $amounts['gross'],
            'vat_key' => $vatKey,
            'period_start' => isset($line['period']['start']) ? Carbon::createFromTimestamp($line['period']['start']) : null,
            'period_end' => isset($line['period']['end']) ? Carbon::createFromTimestamp($line['period']['end']) : null,
            'buyer' => [
                'name' => $workspace->billing_name ?: $workspace->name,
                'email' => $workspace->billing_email ?: $workspace->owner?->email,
                'country' => $workspace->billing_country ?: $workspace->country,
                'postcode' => $workspace->billing_postcode,
                'city' => $workspace->billing_city,
                'address' => $workspace->billing_address,
                'tax_number' => $workspace->billing_tax_number,
                'eu_vat_number' => $workspace->billing_eu_vat_number,
            ],
            'paid_at' => isset($data['status_transitions']['paid_at']) ? Carbon::createFromTimestamp($data['status_transitions']['paid_at']) : now(),
        ]);

        if ($invoice->wasRecentlyCreated && config('billing.szamlazz.enabled')) {
            IssueInvoice::dispatch($invoice->id);
        }

        return $this->successMethod();
    }

    protected function syncWorkspace(array $payload): void
    {
        $workspace = Cashier::findBillable($payload['data']['object']['customer'] ?? null);

        if ($workspace instanceof Workspace) {
            app(BillingService::class)->sync($workspace);
        }
    }

    /**
     * @param  array<string, mixed>  $line
     */
    protected function linePrice(array $line): ?string
    {
        return $line['pricing']['price_details']['price'] ?? $line['price']['id'] ?? null;
    }

    /**
     * Stripe amounts are in the smallest currency unit (HUF is treated as a two-decimal currency).
     */
    protected function toMajorUnits(int $amount, string $currency): float
    {
        $zeroDecimal = ['BIF', 'CLP', 'DJF', 'GNF', 'JPY', 'KMF', 'KRW', 'MGA', 'PYG', 'RWF', 'UGX', 'VND', 'VUV', 'XAF', 'XOF', 'XPF'];

        return in_array($currency, $zeroDecimal, true) ? (float) $amount : $amount / 100;
    }
}
