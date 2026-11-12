<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\ClientResource;
use App\Models\Client;
use App\Models\CustomFieldDefinition;
use App\Services\ActivityLogger;
use App\Services\AuditLogger;
use App\Services\AutomationService;
use App\Services\ExportService;
use App\Services\PlanService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ClientController extends Controller
{
    public function __construct(
        protected PlanService $plans,
        protected ActivityLogger $activities,
        protected AuditLogger $audit,
        protected AutomationService $automations,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $query = $this->filteredQuery($request)->with('owner')
            ->withCount(['tasks as open_tasks_count' => fn ($q) => $q->where('status', '!=', 'done'), 'deals']);

        $this->applySort($query, $request, ['name', 'company', 'email', 'status', 'created_at', 'updated_at']);

        return ClientResource::collection($query->paginate($this->perPage($request)));
    }

    public function store(Request $request): JsonResponse
    {
        $this->plans->ensureWithinLimit($this->workspace(), 'clients');
        $data = $this->validated($request);
        $data['owner_id'] ??= $request->user()->id;

        $client = Client::create($data);
        $this->activities->log($client, 'system', __('crm.activity.created'));
        $this->automations->fire($this->workspace(), 'client_created', $client);

        return ClientResource::make($client->load('owner'))->response()->setStatusCode(201);
    }

    public function show(Client $client): ClientResource
    {
        return ClientResource::make($client->load('owner')->loadCount(['tasks as open_tasks_count' => fn ($q) => $q->where('status', '!=', 'done'), 'deals']));
    }

    public function update(Request $request, Client $client): ClientResource
    {
        $data = $this->validated($request, true);
        $previousStatus = $client->status;
        $client->update($data);

        if (isset($data['status']) && $data['status'] !== $previousStatus) {
            $this->activities->log($client, 'status_change', __('crm.activity.status_changed', ['from' => $previousStatus, 'to' => $data['status']]), null, ['from' => $previousStatus, 'to' => $data['status']]);
        }

        return ClientResource::make($client->load('owner'));
    }

    public function destroy(Client $client): JsonResponse
    {
        $client->delete();
        $this->audit->log('client.deleted', $client, ['name' => $client->name]);

        return response()->json(['ok' => true]);
    }

    /**
     * CSV export for every plan, XLSX ("advanced Excel export") from Pro.
     */
    public function export(Request $request, ExportService $export): StreamedResponse
    {
        $format = $request->query('format') === 'xlsx' ? 'xlsx' : 'csv';

        if ($format === 'xlsx') {
            $this->plans->ensureFeature($this->workspace(), 'excel_export');
        }

        $this->audit->log('client.exported', null, ['format' => $format]);

        $columns = ['name', 'company', 'email', 'phone', 'address', 'type', 'status', 'category', 'tags', 'source', 'notes', 'created_at'];
        $rows = $this->filteredQuery($request)->orderBy('name')->lazy()->map(fn (Client $client) => [
            $client->name, $client->company, $client->email, $client->phone, $client->address, $client->type, $client->status,
            $client->category, implode(', ', $client->tags ?? []), $client->source, $client->notes, $client->created_at?->toDateTimeString(),
        ]);

        return $export->download('clients-'.now()->format('Ymd-His'), $format, $columns, $rows);
    }

    /**
     * @return Builder<Client>
     */
    protected function filteredQuery(Request $request)
    {
        $query = Client::query();
        $advanced = $this->plans->hasFeature($this->workspace(), 'advanced_search');

        $this->applySearch($query, $request->query('search'), ['name', 'company', 'email', 'phone']);

        $query->when($request->query('status'), fn ($q, $status) => $q->where('status', $status))
            ->when($request->query('type'), fn ($q, $type) => $q->where('type', $type))
            ->when($request->query('owner_id'), fn ($q, $owner) => $q->where('owner_id', $owner));

        if ($advanced) {
            $query->when($request->query('category'), fn ($q, $category) => $q->where('category', $category))
                ->when($request->query('tag'), fn ($q, $tag) => $q->whereJsonContains('tags', $tag))
                ->when($request->query('source'), fn ($q, $source) => $q->where('source', $source))
                ->when($request->query('created_from'), fn ($q, $date) => $q->whereDate('created_at', '>=', $date))
                ->when($request->query('created_to'), fn ($q, $date) => $q->whereDate('created_at', '<=', $date));
        }

        return $query;
    }

    /**
     * @return array<string, mixed>
     */
    protected function validated(Request $request, bool $updating = false): array
    {
        $required = $updating ? 'sometimes' : 'required';

        $data = $request->validate([
            'name' => [$required, 'string', 'max:255'],
            'company' => ['nullable', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'address' => ['nullable', 'string', 'max:255'],
            'type' => ['sometimes', Rule::in(Client::TYPES)],
            'status' => ['sometimes', Rule::in(Client::STATUSES)],
            'category' => ['nullable', 'string', 'max:100'],
            'tags' => ['nullable', 'array', 'max:20'],
            'tags.*' => ['string', 'max:50'],
            'notes' => ['nullable', 'string', 'max:10000'],
            'owner_id' => ['nullable', 'integer', $this->memberRule()],
            'custom_fields' => ['nullable', 'array'],
        ]);

        if (array_key_exists('custom_fields', $data)) {
            $allowed = CustomFieldDefinition::where('entity', 'client')->pluck('key')->all();
            $data['custom_fields'] = array_intersect_key($data['custom_fields'] ?? [], array_flip($allowed));
        }

        return $data;
    }
}
