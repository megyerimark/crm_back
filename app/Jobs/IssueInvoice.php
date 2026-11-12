<?php

namespace App\Jobs;

use App\Models\Invoice;
use App\Services\AuditLogger;
use App\Services\Billing\SzamlazzHuClient;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * Issues the Hungarian e-invoice for a paid Stripe invoice. Idempotent: an invoice that already
 * has a number is never issued twice, and Számlázz.hu also receives the Stripe ID as external ID.
 */
class IssueInvoice implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    /**
     * @var list<int>
     */
    public array $backoff = [60, 300, 900, 3600];

    public function __construct(public int $invoiceId) {}

    public function uniqueId(): string
    {
        return (string) $this->invoiceId;
    }

    public function handle(SzamlazzHuClient $client, AuditLogger $audit): void
    {
        $invoice = Invoice::with('workspace')->find($this->invoiceId);

        if (! $invoice || $invoice->number || ! config('billing.szamlazz.enabled')) {
            return;
        }

        $invoice->increment('attempts');
        $periodLabel = $invoice->period_start && $invoice->period_end
            ? $invoice->period_start->toDateString().' – '.$invoice->period_end->toDateString()
            : null;
        $language = $invoice->workspace?->locale ?? 'hu';

        $item = [
            'name' => trans('billing.invoice_item', [
                'plan' => config("plans.plans.{$invoice->plan}.name", ucfirst((string) $invoice->plan)),
                'interval' => trans('billing.interval.'.($invoice->interval ?? 'monthly'), [], $language),
            ], $language),
            'quantity' => 1.0,
            'unit' => trans('billing.unit.'.($invoice->interval ?? 'monthly'), [], $language),
            'net_unit_price' => (float) $invoice->net_amount,
            'net' => (float) $invoice->net_amount,
            'vat' => (float) $invoice->vat_amount,
            'gross' => (float) $invoice->gross_amount,
            'comment' => $periodLabel,
        ];

        try {
            $result = $client->createInvoice($invoice, $item, $language);
        } catch (Throwable $exception) {
            $invoice->forceFill(['status' => 'failed', 'error' => Str::limit($exception->getMessage(), 1000)])->save();

            throw $exception;
        }

        $path = null;

        if ($result['pdf']) {
            $path = 'invoices/'.$invoice->workspace_id.'/'.Str::slug($result['number']).'.pdf';
            Storage::disk('local')->put($path, $result['pdf']);
        }

        $invoice->forceFill([
            'number' => $result['number'],
            'status' => 'issued',
            'pdf_path' => $path,
            'issued_at' => now(),
            'error' => null,
        ])->save();

        $audit->log('invoice.issued', null, ['invoice_id' => $invoice->id, 'number' => $result['number']], $invoice->workspace_id);
    }
}
