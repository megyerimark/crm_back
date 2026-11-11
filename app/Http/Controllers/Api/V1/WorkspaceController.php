<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\Plan;
use App\Http\Controllers\Controller;
use App\Http\Resources\WorkspaceResource;
use App\Services\AuditLogger;
use App\Services\PlanService;
use App\Services\WorkspaceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class WorkspaceController extends Controller
{
    public function __construct(protected PlanService $plans, protected AuditLogger $audit) {}

    public function index(Request $request): JsonResponse
    {
        return response()->json(['data' => WorkspaceResource::collection($request->user()->workspaces()->orderBy('name')->get())]);
    }

    public function store(Request $request, WorkspaceService $service): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'country' => ['nullable', 'string', 'size:2'],
            'timezone' => ['nullable', 'timezone:all'],
            'currency' => ['nullable', 'string', 'size:3'],
            'locale' => ['nullable', Rule::in(['hu', 'en', 'de'])],
        ]);

        $workspace = $service->create($request->user(), $data);
        $this->audit->log('workspace.created', $workspace, [], $workspace->id);

        return response()->json(['data' => WorkspaceResource::make($request->user()->workspaces()->find($workspace->id))], 201);
    }

    public function switch(Request $request, int $workspace): JsonResponse
    {
        $membership = $request->user()->workspaces()->whereKey($workspace)->firstOrFail();
        $request->user()->forceFill(['current_workspace_id' => $membership->id])->save();

        return response()->json(['data' => WorkspaceResource::make($membership)]);
    }

    /**
     * Current workspace with plan, limits and usage.
     */
    public function current(Request $request): JsonResponse
    {
        $workspace = $request->user()->workspaces()->find($this->workspace()->id);

        return response()->json([
            'data' => WorkspaceResource::make($workspace),
            'subscription' => $this->plans->summary($workspace),
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        $workspace = $this->workspace();
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'country' => ['sometimes', 'string', 'size:2'],
            'timezone' => ['sometimes', 'timezone:all'],
            'currency' => ['sometimes', 'string', 'size:3'],
            'locale' => ['sometimes', Rule::in(['hu', 'en', 'de'])],
        ]);

        $workspace->update($data);
        $this->audit->log('workspace.updated', $workspace, ['changes' => array_keys($data)]);

        return response()->json(['data' => WorkspaceResource::make($request->user()->workspaces()->find($workspace->id))]);
    }

    /**
     * Public plan catalogue (prices, limits and features).
     */
    public function plans(): JsonResponse
    {
        return response()->json([
            'data' => collect(Plan::cases())->map(fn (Plan $plan) => ['key' => $plan->value] + $this->plans->definition($plan))->values(),
            'trial_days' => config('plans.trial_days'),
        ]);
    }

    /**
     * Tells the owner in advance which data would be over the limits after a downgrade.
     */
    public function downgradePreview(Request $request): JsonResponse
    {
        $data = $request->validate(['plan' => ['required', Rule::enum(Plan::class)]]);

        return response()->json(['over_limits' => $this->plans->overLimitsFor($this->workspace(), Plan::from($data['plan']))]);
    }
}
