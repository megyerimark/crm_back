<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureSuperAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user?->is_super_admin) {
            return response()->json(['message' => __('crm.insufficient_role')], 403);
        }

        /* The platform console controls every tenant: two-factor authentication is mandatory. */
        if (config('admin.require_two_factor') && ! $user->hasTwoFactorEnabled()) {
            return response()->json(['message' => __('account.admin_two_factor_required'), 'code' => 'two_factor_required'], 403);
        }

        return $next($request);
    }
}
