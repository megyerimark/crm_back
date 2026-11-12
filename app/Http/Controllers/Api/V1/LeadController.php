<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\ClientResource;
use App\Http\Resources\LeadResource;
use App\Models\Client;
use App\Models\Lead;
use App\Services\ActivityLogger;
use App\Services\AuditLogger;
use App\Services\AutomationService;
use App\Services\LeadConversionService;
use App\Services\PlanService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

class LeadController extends Controller
{
    public function __construct(
        protected PlanService $plans,
        protected ActivityLogger $activities,
        protected AuditLogger $audit,
        protected AutomationService $automations,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $query = Lead::query()->with(['owner', 'form']);

        $this->applySearch($query, $request->query('search'), ['name', 'company', 'email', 'phone']);

        $query->when($request->query('status'), fn ($q, $status) => $q->where('status', $status))
            ->when($request->query('source'), fn ($q, $source) => $q->where('source', $source))
            ->when($request->query('form_id'), fn ($q, $form) => $q->where('form_id', $form))
            ->when($request->query('owner_id'), fn ($q, $owner) => $q->where('owner_id', $owner))
            ->when($request->query('utm_source'), fn ($q, $value) => $q->where('utm_source', $value))
            ->when($request->boolean('active'), fn ($q) => $q->active());

        $this->applySort($query, $request, ['name', 'status', 'source', 'created_at', 'updated_at']);

        return LeadResource::collection($query->paginate($this->perPage($request)));
    }

    public function store(Request $request): JsonResponse
    {
        $this->plans->ensureWithinLimit($this->workspace(), 'active_leads');
        $data = $this->validated($request);
        $data['owner_id'] ??= $request->user()->id;
        $data['source'] = 'manual';

        $lead = Lead::create($data);
        $this->activities->log($lead, 'system', __('crm.activity.created'));
        $this->automations->fire($this->workspace(), 'lead_created', $lead);

        return LeadResource::make($lead->load('owner', 'form'))->response()->setStatusCode(201);
    }

    public function show(Lead $lead): LeadResource
    {
        return LeadResource::make($lead->load(['owner', 'form', 'submissions' => fn ($q) => $q->latest()]));
    }

    public function update(Request $request, Lead $lead): LeadResource
    {
        $data = $this->validated($request, true);
        $previousStatus = $lead->status;

        if (isset($data['status']) && $data['status'] !== $previousStatus && in_array($previousStatus, ['unqualified', 'converted'], true) && ! $lead->isConverted()) {
            /* Re-opening an inactive lead counts against the active lead limit again. */
            $this->plans->ensureWithinLimit($this->workspace(), 'active_leads');
        }

        $lead->update($data);

        if (isset($data['status']) && $data['status'] !== $previousStatus) {
            $this->activities->log($lead, 'status_change', __('crm.activity.status_changed', ['from' => $previousStatus, 'to' => $data['status']]), null, ['from' => $previousStatus, 'to' => $data['status']]);
        }

        return LeadResource::make($lead->load('owner', 'form'));
    }

    public function destroy(Lead $lead): JsonResponse
    {
        $lead->delete();
        $this->audit->log('lead.deleted', $lead, ['name' => $lead->name]);

        return response()->json(['ok' => true]);
    }

    /**
     * Converts the lead into a new client or links it to an existing one (logged).
     */
    public function convert(Request $request, Lead $lead, LeadConversionService $conversion): JsonResponse
    {
        $data = $request->validate([
            'client_id' => ['nullable', 'integer', $this->ownedRule('clients')],
            'type' => ['nullable', Rule::in(Client::TYPES)],
            'status' => ['nullable', Rule::in(Client::STATUSES)],
        ]);

        $client = $conversion->convert($this->workspace(), $lead, $data);

        return response()->json([
            'client' => ClientResource::make($client->load('owner')),
            'lead' => LeadResource::make($lead->refresh()->load('owner', 'form')),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    protected function validated(Request $request, bool $updating = false): array
    {
        $required = $updating ? 'sometimes' : 'required';

        return $request->validate([
            'name' => [$required, 'string', 'max:255'],
            'company' => ['nullable', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'message' => ['nullable', 'string', 'max:10000'],
            'status' => ['sometimes', Rule::in(['new', 'contacted', 'qualified', 'unqualified'])],
            'tags' => ['nullable', 'array', 'max:20'],
            'tags.*' => ['string', 'max:50'],
            'owner_id' => ['nullable', 'integer', $this->memberRule()],
        ]);
    }
}
