<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\TaskResource;
use App\Models\Task;
use App\Services\PlanService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

class TaskController extends Controller
{
    public function __construct(protected PlanService $plans) {}

    /**
     * List view (paginated) or calendar view (?from=&to= returns all tasks due in the range).
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $query = Task::query()->with(['assignee', 'client', 'lead', 'deal']);

        $this->applySearch($query, $request->query('search'), ['title', 'description']);

        $query->when($request->query('status'), fn ($q, $status) => $status === 'open' ? $q->open() : $q->where('status', $status))
            ->when($request->query('priority'), fn ($q, $priority) => $q->where('priority', $priority))
            ->when($request->query('assignee_id'), fn ($q, $id) => $q->where('assignee_id', $id === 'me' ? $request->user()->id : $id))
            ->when($request->query('client_id'), fn ($q, $id) => $q->where('client_id', $id))
            ->when($request->query('lead_id'), fn ($q, $id) => $q->where('lead_id', $id))
            ->when($request->query('deal_id'), fn ($q, $id) => $q->where('deal_id', $id))
            ->when($request->boolean('overdue'), fn ($q) => $q->overdue());

        if ($request->filled(['from', 'to'])) {
            $request->validate(['from' => ['date'], 'to' => ['date', 'after_or_equal:from']]);
            $tasks = $query->whereBetween('due_at', [$request->date('from'), $request->date('to')])->orderBy('due_at')->limit(1000)->get();

            return TaskResource::collection($tasks);
        }

        if (! $request->query('sort')) {
            $query->orderByRaw('case when status = ? then 1 else 0 end', ['done'])->orderByRaw('due_at is null')->orderBy('due_at');
        } else {
            $this->applySort($query, $request, ['title', 'due_at', 'priority', 'status', 'created_at']);
        }

        return TaskResource::collection($query->paginate($this->perPage($request)));
    }

    public function store(Request $request): JsonResponse
    {
        $this->plans->ensureWithinLimit($this->workspace(), 'active_tasks');
        $data = $this->validated($request);
        $data['assignee_id'] ??= $request->user()->id;
        $data['created_by'] = $request->user()->id;

        $task = Task::create($data);
        $this->syncCompletion($task);

        return TaskResource::make($task->load('assignee', 'client', 'lead', 'deal'))->response()->setStatusCode(201);
    }

    public function show(Task $task): TaskResource
    {
        return TaskResource::make($task->load('assignee', 'client', 'lead', 'deal'));
    }

    public function update(Request $request, Task $task): TaskResource
    {
        $data = $this->validated($request, true);

        if (($data['status'] ?? null) && $data['status'] !== 'done' && $task->status === 'done') {
            $this->plans->ensureWithinLimit($this->workspace(), 'active_tasks');
        }

        if (array_key_exists('due_at', $data)) {
            $data['reminder_sent_at'] = null;
        }

        $task->forceFill($data)->save();
        $this->syncCompletion($task);

        return TaskResource::make($task->load('assignee', 'client', 'lead', 'deal'));
    }

    public function destroy(Task $task): JsonResponse
    {
        $task->delete();

        return response()->json(['ok' => true]);
    }

    protected function syncCompletion(Task $task): void
    {
        if ($task->status === 'done' && ! $task->completed_at) {
            $task->forceFill(['completed_at' => now()])->save();
        } elseif ($task->status !== 'done' && $task->completed_at) {
            $task->forceFill(['completed_at' => null])->save();
        }
    }

    /**
     * @return array<string, mixed>
     */
    protected function validated(Request $request, bool $updating = false): array
    {
        $required = $updating ? 'sometimes' : 'required';

        return $request->validate([
            'title' => [$required, 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:10000'],
            'due_at' => ['nullable', 'date'],
            'priority' => ['sometimes', Rule::in(Task::PRIORITIES)],
            'status' => ['sometimes', Rule::in(Task::STATUSES)],
            'assignee_id' => ['nullable', 'integer', $this->memberRule()],
            'client_id' => ['nullable', 'integer', $this->ownedRule('clients')],
            'lead_id' => ['nullable', 'integer', $this->ownedRule('leads')],
            'deal_id' => ['nullable', 'integer', $this->ownedRule('deals')],
        ]);
    }
}
