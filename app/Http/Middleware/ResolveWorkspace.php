<?php

namespace App\Http\Middleware;

use App\Enums\WorkspaceRole;
use App\Models\Workspace;
use App\Support\CurrentWorkspace;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Resolves the workspace from the X-Workspace-Id header, verifies membership on every request
 * and blocks write requests for read-only roles. The frontend is never trusted as a security boundary.
 */
class ResolveWorkspace
{
    public function __construct(protected CurrentWorkspace $current) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $workspaceId = $request->header('X-Workspace-Id') ?: $user?->current_workspace_id;

        if (! $user || ! $workspaceId || ! ctype_digit((string) $workspaceId)) {
            return response()->json(['message' => __('crm.workspace_required'), 'code' => 'workspace_required'], 400);
        }

        $workspace = $user->workspaces()->whereKey((int) $workspaceId)->first();

        if (! $workspace instanceof Workspace) {
            return response()->json(['message' => __('crm.not_a_member'), 'code' => 'not_a_member'], 403);
        }

        if ($workspace->suspended_at !== null) {
            return response()->json(['message' => __('crm.suspended'), 'code' => 'suspended'], 423);
        }

        $role = WorkspaceRole::from($workspace->pivot->role);

        if (! $role->canWrite() && ! $request->isMethodSafe()) {
            return response()->json(['message' => __('crm.read_only_role'), 'code' => 'read_only'], 403);
        }

        $this->current->set($workspace, $role);

        try {
            return $next($request);
        } finally {
            $this->current->clear();
        }
    }
}
