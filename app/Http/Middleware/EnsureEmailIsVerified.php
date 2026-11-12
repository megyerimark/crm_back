<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * API variant of Laravel's "verified" middleware: JSON 403 with a machine readable code.
 */
class EnsureEmailIsVerified
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->user()?->hasVerifiedEmail()) {
            return response()->json(['message' => __('account.verify_required'), 'code' => 'email_unverified'], 403);
        }

        return $next($request);
    }
}
