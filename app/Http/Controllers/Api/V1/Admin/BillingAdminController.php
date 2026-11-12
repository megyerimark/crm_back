<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\IssueInvoice;
use App\Models\Invoice;
use App\Models\Workspace;
use App\Services\AuditLogger;
use App\Services\Billing\BillingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Stripe subscriptions and Számlázz.hu invoices across the platform.
 */
class BillingAdminController extends Controller
{
    public function subscriptions(Request $request, BillingService $billing): JsonResponse
    {
        $query = Workspace::query()->with('owner')->where(function ($q) {
            $q->whereNotNull('subscription_status')->orWhereNotNull('stripe_id');
        });
        $query->when($request->query('status'), fn ($q, $status) => $q->where('subscription_status', $status));
        $this->applySearch($query, $request->query('search'), ['name']);

        $page = $query->latest('updated_at')->paginate($this->perPage($request));

        return response()->json($page->through(function (Workspace $workspace) use ($billing) {
            $subscription = $billing->subscription($workspace);

            return [
                'workspace' => ['id' => $workspace->id, 'name' => $workspace->name],
                'owner' => $workspace->owner?->only(['id', 'name', 'email']),
                'plan' => $workspace->plan,
                'interval' => $workspace->billing_interval,
                'status' => $workspace->subscription_status,
                'source' => $subscription ? 'stripe' : 'manual',
                'stripe_status' => $subscription?->stripe_status,
                'stripe_id' => $subscription?->stripe_id,
                'ends_at' => $workspace->subscription_ends_at,
                'on_grace_period' => $subscription?->onGracePeriod() ?? false,
                'payment_method' => $workspace->pm_last_four ? $workspace->pm_type.' •••• '.$workspace->pm_last_four : null,
            ];
        }));
    }

    public function invoices(Request $request): JsonResponse
    {
        $query = Invoice::query()->with('workspace')->latest('id');
        $query->when($request->query('status'), fn ($q, $status) => $q->where('status', $status));
        $this->applySearch($query, $request->query('search'), ['number', 'stripe_number']);

        $page = $query->paginate($this->perPage($request));

        return response()->json($page->through(fn (Invoice $invoice) => [
            'id' => $invoice->id,
            'number' => $invoice->number,
            'stripe_number' => $invoice->stripe_number,
            'status' => $invoice->status,
            'workspace' => $invoice->workspace ? ['id' => $invoice->workspace->id, 'name' => $invoice->workspace->name] : null,
            'buyer' => $invoice->buyer['name'] ?? null,
            'plan' => $invoice->plan,
            'interval' => $invoice->interval,
            'gross_amount' => (float) $invoice->gross_amount,
            'currency' => $invoice->currency,
            'attempts' => $invoice->attempts,
            'error' => $invoice->error,
            'paid_at' => $invoice->paid_at,
        ])->toArray() + ['totals' => [
            'issued' => Invoice::where('status', 'issued')->count(),
            'pending' => Invoice::where('status', 'pending')->count(),
            'failed' => Invoice::where('status', 'failed')->count(),
            'revenue_30d' => (float) Invoice::where('paid_at', '>=', now()->subDays(30))->sum('gross_amount'),
        ]]);
    }

    public function retryInvoice(Invoice $invoice, AuditLogger $audit): JsonResponse
    {
        abort_if($invoice->number !== null, 422, 'Already issued.');

        $invoice->forceFill(['status' => 'pending', 'error' => null])->save();
        IssueInvoice::dispatch($invoice->id);
        $audit->log('admin.invoice.retried', null, ['invoice_id' => $invoice->id], $invoice->workspace_id, true);

        return response()->json(['ok' => true, 'status' => $invoice->fresh()->status]);
    }
}
