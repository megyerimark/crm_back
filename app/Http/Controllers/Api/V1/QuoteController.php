<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\QuoteResource;
use App\Models\Client;
use App\Models\Lead;
use App\Models\Quote;
use App\Services\PlanService;
use App\Services\Quotes\QuoteService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class QuoteController extends Controller
{
    public function __construct(protected QuoteService $quotes, protected PlanService $plans) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $query = Quote::query()->with(['client', 'lead', 'owner']);

        $query->when($request->query('status'), fn ($q, $status) => $status === 'awaiting' ? $q->whereIn('status', Quote::AWAITING) : $q->where('status', $status))
            ->when($request->query('client_id'), fn ($q, $id) => $q->where('client_id', $id))
            ->when($request->query('lead_id'), fn ($q, $id) => $q->where('lead_id', $id))
            ->when($request->query('deal_id'), fn ($q, $id) => $q->where('deal_id', $id))
            ->when($request->query('owner_id'), fn ($q, $id) => $q->where('owner_id', $id));

        $this->applySearch($query, $request->query('search'), ['title', 'number', 'recipient_name', 'recipient_email']);
        $this->applySort($query, $request, ['created_at', 'sent_at', 'gross_total', 'valid_until', 'number']);

        return QuoteResource::collection($query->paginate($this->perPage($request)))->additional(['stats' => $this->stats()]);
    }

    public function store(Request $request): JsonResponse
    {
        $workspace = $this->workspace();
        $this->plans->ensureWithinLimit($workspace, 'quotes_per_month');
        $data = $this->validated($request);

        $quote = DB::transaction(function () use ($data, $request, $workspace): Quote {
            $quote = Quote::create([
                ...collect($data)->except('items')->all(),
                'number' => $this->quotes->nextNumber($workspace),
                'owner_id' => $data['owner_id'] ?? $request->user()->id,
                'currency' => $data['currency'] ?? $workspace->currency,
                'valid_until' => array_key_exists('valid_until', $data) ? $data['valid_until'] : now()->addDays(14)->toDateString(),
                ...$this->recipientDefaults($data),
            ]);
            $this->quotes->syncItems($quote, $data['items'] ?? []);
            $this->quotes->record($quote, 'created', [], $request->user());

            return $quote;
        });

        return QuoteResource::make($this->loaded($quote))->response()->setStatusCode(201);
    }

    public function show(Quote $quote): QuoteResource
    {
        return QuoteResource::make($this->loaded($quote));
    }

    public function update(Request $request, Quote $quote): QuoteResource
    {
        $data = $this->validated($request, true);
        $onlyFollowUp = array_keys($data) === ['follow_up_at'];

        if (! $quote->isDraft() && ! $onlyFollowUp) {
            throw ValidationException::withMessages(['status' => __('quotes.errors.not_editable')]);
        }

        DB::transaction(function () use ($quote, $data): void {
            $quote->update(collect($data)->except('items')->all());

            if (array_key_exists('items', $data)) {
                $this->quotes->syncItems($quote, $data['items']);
            }
        });

        return QuoteResource::make($this->loaded($quote->refresh()));
    }

    public function destroy(Quote $quote): JsonResponse
    {
        $quote->delete();

        return response()->json(['ok' => true]);
    }

    public function send(Request $request, Quote $quote): QuoteResource
    {
        $data = $request->validate([
            'email' => ['required', 'email:rfc', 'max:255'],
            'name' => ['nullable', 'string', 'max:255'],
            'message' => ['nullable', 'string', 'max:2000'],
        ]);

        $this->quotes->send($quote, $request->user(), $data['email'], $data['name'] ?? null, $data['message'] ?? null);

        return QuoteResource::make($this->loaded($quote->refresh()));
    }

    /**
     * Records an answer the customer gave outside the online link (phone, e-mail, in person).
     */
    public function mark(Request $request, Quote $quote): QuoteResource
    {
        $data = $request->validate([
            'status' => ['required', Rule::in(['accepted', 'declined'])],
            'name' => ['nullable', 'string', 'max:255'],
            'reason' => ['nullable', 'string', 'max:2000'],
        ]);

        $data['status'] === 'accepted'
            ? $this->quotes->accept($quote, $data['name'] ?? ($quote->recipient_name ?: $request->user()->name), $request->user())
            : $this->quotes->decline($quote, $data['reason'] ?? null, $request->user());

        return QuoteResource::make($this->loaded($quote->refresh()));
    }

    public function duplicate(Request $request, Quote $quote): JsonResponse
    {
        $workspace = $this->workspace();
        $this->plans->ensureWithinLimit($workspace, 'quotes_per_month');

        $copy = DB::transaction(function () use ($quote, $request, $workspace): Quote {
            $copy = Quote::create([
                ...$quote->only(['title', 'client_id', 'lead_id', 'deal_id', 'recipient_name', 'recipient_email', 'currency', 'introduction', 'terms']),
                'number' => $this->quotes->nextNumber($workspace),
                'owner_id' => $request->user()->id,
                'valid_until' => now()->addDays(14)->toDateString(),
            ]);
            $this->quotes->syncItems($copy, $quote->items->map->only(['description', 'quantity', 'unit', 'unit_price', 'vat_rate'])->all());
            $this->quotes->record($copy, 'created', ['from' => $quote->number], $request->user());

            return $copy;
        });

        return QuoteResource::make($this->loaded($copy))->response()->setStatusCode(201);
    }

    protected function loaded(Quote $quote): Quote
    {
        return $quote->load(['client', 'lead', 'deal', 'owner', 'items', 'events.user']);
    }

    /**
     * Pipeline of quotes for the header cards (counts and values per status, acceptance rate).
     *
     * @return array<string, mixed>
     */
    protected function stats(): array
    {
        $rows = Quote::query()->selectRaw('status, count(*) as total, sum(gross_total) as value')->groupBy('status')->get()->toBase()->keyBy('status');
        $value = fn (array $statuses) => (float) $rows->only($statuses)->sum('value');
        $count = fn (array $statuses) => (int) $rows->only($statuses)->sum('total');
        $answered = $count(['accepted', 'declined', 'expired']);

        return [
            'counts' => collect(Quote::STATUSES)->mapWithKeys(fn ($status) => [$status => (int) ($rows[$status]->total ?? 0)]),
            'awaiting_value' => $value(Quote::AWAITING),
            'accepted_value' => $value(['accepted']),
            'acceptance_rate' => $answered > 0 ? round($count(['accepted']) / $answered * 100) : null,
            'follow_ups_due' => Quote::query()->whereIn('status', Quote::AWAITING)->where('follow_up_at', '<=', now())->count(),
        ];
    }

    /**
     * Fills the recipient from the linked client or lead when it was not given.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function recipientDefaults(array $data): array
    {
        $contact = ! empty($data['client_id']) ? Client::find($data['client_id']) : (! empty($data['lead_id']) ? Lead::find($data['lead_id']) : null);

        return [
            'recipient_name' => $data['recipient_name'] ?? $contact?->name,
            'recipient_email' => $data['recipient_email'] ?? $contact?->email,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function validated(Request $request, bool $updating = false): array
    {
        $required = $updating ? 'sometimes' : 'required';

        return $request->validate([
            'title' => [$required, 'string', 'max:255'],
            'client_id' => ['nullable', 'integer', $this->ownedRule('clients')],
            'lead_id' => ['nullable', 'integer', $this->ownedRule('leads')],
            'deal_id' => ['nullable', 'integer', $this->ownedRule('deals')],
            'owner_id' => ['nullable', 'integer', $this->memberRule()],
            'recipient_name' => ['nullable', 'string', 'max:255'],
            'recipient_email' => ['nullable', 'email:rfc', 'max:255'],
            'currency' => ['nullable', 'string', 'size:3'],
            'valid_until' => ['nullable', 'date'],
            'introduction' => ['nullable', 'string', 'max:5000'],
            'terms' => ['nullable', 'string', 'max:5000'],
            'follow_up_at' => ['nullable', 'date'],
            'items' => [$updating ? 'sometimes' : 'nullable', 'array', 'max:100'],
            'items.*.description' => ['required', 'string', 'max:500'],
            'items.*.quantity' => ['nullable', 'numeric', 'min:0', 'max:9999999'],
            'items.*.unit' => ['nullable', 'string', 'max:30'],
            'items.*.unit_price' => ['nullable', 'numeric', 'min:0', 'max:999999999999'],
            'items.*.vat_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
        ]);
    }
}
