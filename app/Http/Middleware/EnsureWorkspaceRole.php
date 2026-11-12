<?php

namespace App\Http\Middleware;

use App\Enums\WorkspaceRole;
use App\Support\CurrentWorkspace;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureWorkspaceRole
{
    public function __construct(protected CurrentWorkspace $current) {}

    public function handle(Request $request, Closure $next, string $minimum): Response
    {
        $role = $this->current->role();

        if (! $role || ! $role->atLeast(WorkspaceRole::from($minimum))) {
            return response()->json(['message' => __('crm.insufficient_role'), 'code' => 'insufficient_role'], 403);
        }

        return $next($request);
    }
}
