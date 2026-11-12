<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Quote;
use App\Models\Workspace;
use App\Services\Quotes\QuoteService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The customer facing quote page, reachable only with the unguessable token from the e-mail.
 * Exposes no internal ids and nothing outside the quote itself.
 */
class PublicQuoteController extends Controller
{
    public function __construct(protected QuoteService $quotes) {}

    public function show(Request $request, string $token): JsonResponse
    {
        $quote = $this->find($token);

        if (! $request->boolean('preview')) {
            $this->quotes->registerView($quote);
        }

        return $this->present($quote);
    }

    public function accept(Request $request, string $token): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'agree' => ['accepted'],
        ]);

        $quote = $this->find($token);
        $this->quotes->accept($quote, $data['name']);

        return $this->present($quote->refresh());
    }

    public function decline(Request $request, string $token): JsonResponse
    {
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:2000']]);

        $quote = $this->find($token);
        $this->quotes->decline($quote, $data['reason'] ?? null);

        return $this->present($quote->refresh());
    }

    protected function find(string $token): Quote
    {
        abort_unless(strlen($token) === 48 && ctype_alnum($token), 404);

        return Quote::withoutGlobalScopes()->where('public_token', $token)->whereNull('deleted_at')->whereNot('status', 'draft')->firstOrFail();
    }

    protected function present(Quote $quote): JsonResponse
    {
        $workspace = Workspace::findOrFail($quote->workspace_id);
        $owner = $quote->owner;

        return response()->json(['data' => [
            'number' => $quote->number,
            'title' => $quote->title,
            'status' => $quote->status,
            'currency' => $quote->currency,
            'net_total' => (float) $quote->net_total,
            'vat_total' => (float) $quote->vat_total,
            'gross_total' => (float) $quote->gross_total,
            'valid_until' => $quote->valid_until?->toDateString(),
            'is_expired' => $quote->status === 'expired' || ($quote->isAwaitingAnswer() && $quote->isPastValidity()),
            'introduction' => $quote->introduction,
            'terms' => $quote->terms,
            'recipient_name' => $quote->recipient_name,
            'sent_at' => $quote->sent_at,
            'accepted_at' => $quote->accepted_at,
            'accepted_by_name' => $quote->accepted_by_name,
            'declined_at' => $quote->declined_at,
            'items' => $quote->items()->get()->map(fn ($item) => [
                'description' => $item->description,
                'quantity' => (float) $item->quantity,
                'unit' => $item->unit,
                'unit_price' => (float) $item->unit_price,
                'vat_rate' => (float) $item->vat_rate,
                'net_amount' => $item->netAmount(),
            ]),
            'company' => [
                'name' => $workspace->billing_name ?: $workspace->name,
                'address' => collect([$workspace->billing_postcode, $workspace->billing_city, $workspace->billing_address])->filter()->implode(' ') ?: null,
                'tax_number' => $workspace->billing_tax_number,
                'locale' => $workspace->locale,
            ],
            'contact' => $owner ? ['name' => $owner->name, 'email' => $owner->email] : null,
        ]]);
    }
}
