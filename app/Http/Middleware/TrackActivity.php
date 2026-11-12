<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * Records "last seen" and one row per active day for platform analytics (DAU / WAU / MAU).
 * Writes at most once every five minutes per user.
 */
class TrackActivity
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        $user = $request->user('sanctum');

        if ($user && (! $user->last_seen_at || $user->last_seen_at->lt(now()->subMinutes(5)))) {
            $user->forceFill(['last_seen_at' => now()])->saveQuietly();
            DB::table('user_activity_days')->insertOrIgnore(['user_id' => $user->id, 'day' => now()->toDateString()]);
        }

        return $response;
    }
}
