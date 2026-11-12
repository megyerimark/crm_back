<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\Plan;
use App\Http\Controllers\Controller;
use App\Http\Resources\AuditLogResource;
use App\Models\AuditLog;
use App\Models\Client;
use App\Models\FormSubmission;
use App\Models\Lead;
use App\Models\User;
use App\Models\Workspace;
use App\Services\AuditLogger;
use App\Services\PlanService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

/**
 * VállalkozóOS Super Admin: operator console, separate from the workspace Admin role.
 * Every access and action is written to the audit log.
 */
class AdminController extends Controller
{
    public function __construct(protected PlanService $plans, protected AuditLogger $audit) {}

    public function stats(): JsonResponse
    {
        $this->audit->log('admin.stats.viewed', null, [], null, true);

        return response()->json([
            'users' => User::count(),
            'workspaces' => Workspace::count(),
            'workspaces_on_trial' => Workspace::where('trial_ends_at', '>', now())->count(),
            'workspaces_paid' => Workspace::where('subscription_status', 'active')->whereIn('plan', ['pro', 'business'])->count(),
            'workspaces_suspended' => Workspace::whereNotNull('suspended_at')->count(),
            'plans' => Workspace::selectRaw('plan, count(*) as total')->groupBy('plan')->pluck('total', 'plan'),
            'clients' => Client::withoutGlobalScopes()->count(),
            'leads' => Lead::withoutGlobalScopes()->count(),
            'submissions_30_days' => FormSubmission::withoutGlobalScopes()->where('created_at', '>=', now()->subDays(30))->count(),
            'signups_30_days' => User::where('created_at', '>=', now()->subDays(30))->count(),
        ]);
    }

    public function workspaces(Request $request): JsonResponse
    {
        $query = Workspace::query()->with('owner')->withCount('members');
        $this->applySearch($query, $request->query('search'), ['name', 'slug']);
        $query->when($request->query('plan'), fn ($q, $plan) => $q->where('plan', $plan));

        $page = $query->latest()->paginate($this->perPage($request));

        return response()->json($page->through(fn (Workspace $workspace) => $this->workspacePayload($workspace)));
    }

    public function showWorkspace(Workspace $workspace): JsonResponse
    {
        $this->audit->log('admin.workspace.viewed', $workspace, [], $workspace->id, true);

        return response()->json([
            'data' => $this->workspacePayload($workspace->load('owner')->loadCount('members')),
            'subscription' => $this->plans->summary($workspace),
            'members' => $workspace->members()->get()->map(fn (User $user) => ['id' => $user->id, 'name' => $user->name, 'email' => $user->email, 'role' => $user->pivot->role]),
        ]);
    }

    /**
     * Manual plan / trial / subscription management until the Stripe integration lands.
     */
    public function updateWorkspace(Request $request, Workspace $workspace): JsonResponse
    {
        $data = $request->validate([
            'plan' => ['sometimes', Rule::enum(Plan::class)],
            'subscription_status' => ['sometimes', 'nullable', Rule::in(['active', 'past_due', 'canceled'])],
            'subscription_ends_at' => ['sometimes', 'nullable', 'date'],
            'trial_ends_at' => ['sometimes', 'nullable', 'date'],
            'extend_trial_days' => ['sometimes', 'integer', 'min:1', 'max:90'],
            'suspended' => ['sometimes', 'boolean'],
        ]);

        $before = $workspace->only(['plan', 'subscription_status', 'trial_ends_at', 'suspended_at']);

        if (isset($data['extend_trial_days'])) {
            $base = $workspace->onGenericTrial() ? $workspace->trial_ends_at : now();
            $workspace->trial_ends_at = $base->copy()->addDays($data['extend_trial_days']);
        }

        if (array_key_exists('suspended', $data)) {
            $workspace->suspended_at = $data['suspended'] ? now() : null;
        }

        $workspace->fill(collect($data)->only(['plan', 'subscription_status', 'subscription_ends_at', 'trial_ends_at'])->all())->save();

        $this->audit->log('admin.workspace.updated', $workspace, ['before' => $before, 'changes' => $data], $workspace->id, true);

        return response()->json(['data' => $this->workspacePayload($workspace->load('owner')->loadCount('members'))]);
    }

    public function users(Request $request): JsonResponse
    {
        $query = User::query()->withCount('workspaces');
        $this->applySearch($query, $request->query('search'), ['name', 'email']);

        $page = $query->latest()->paginate($this->perPage($request));

        return response()->json($page->through(fn (User $user) => [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'is_super_admin' => $user->is_super_admin,
            'workspaces_count' => $user->workspaces_count,
            'last_login_at' => $user->last_login_at,
            'created_at' => $user->created_at,
        ]));
    }

    public function auditLogs(Request $request): AnonymousResourceCollection
    {
        $query = AuditLog::with(['user', 'workspace'])->latest('id');
        $query->when($request->query('workspace_id'), fn ($q, $id) => $q->where('workspace_id', $id))
            ->when($request->query('action'), fn ($q, $action) => $q->where('action', 'like', $action.'%'))
            ->when($request->boolean('admin_only'), fn ($q) => $q->where('is_admin_action', true));

        return AuditLogResource::collection($query->paginate($this->perPage($request, 50)));
    }

    /**
     * @return array<string, mixed>
     */
    protected function workspacePayload(Workspace $workspace): array
    {
        return [
            'id' => $workspace->id,
            'name' => $workspace->name,
            'slug' => $workspace->slug,
            'owner' => $workspace->owner ? ['id' => $workspace->owner->id, 'name' => $workspace->owner->name, 'email' => $workspace->owner->email] : null,
            'members_count' => $workspace->members_count,
            'plan' => $workspace->plan,
            'effective_plan' => $this->plans->effectivePlan($workspace)->value,
            'trial_ends_at' => $workspace->trial_ends_at,
            'subscription_status' => $workspace->subscription_status,
            'subscription_ends_at' => $workspace->subscription_ends_at,
            'suspended_at' => $workspace->suspended_at,
            'created_at' => $workspace->created_at,
        ];
    }
}
