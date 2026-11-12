<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Services\AuditLogger;
use App\Services\Billing\BillingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * System health: services, queue, failed jobs, scheduler heartbeat, configuration and recent errors.
 */
class SystemController extends Controller
{
    public const HEARTBEAT_KEY = 'scheduler:heartbeat';

    public function show(BillingService $billing): JsonResponse
    {
        $checks = [];
        $check = function (string $key, string $status, ?string $detail = null) use (&$checks): void {
            $checks[] = ['key' => $key, 'status' => $status, 'detail' => $detail];
        };

        try {
            DB::select('select 1');
            $check('database', 'ok', config('database.default'));
        } catch (\Throwable $exception) {
            $check('database', 'error', Str::limit($exception->getMessage(), 200));
        }

        try {
            Cache::put('health:probe', 1, 10);
            $check('cache', Cache::get('health:probe') === 1 ? 'ok' : 'error', config('cache.default'));
        } catch (\Throwable $exception) {
            $check('cache', 'error', Str::limit($exception->getMessage(), 200));
        }

        try {
            Storage::disk('local')->put('health/probe.txt', 'ok');
            $check('storage', 'ok', 'local');
        } catch (\Throwable $exception) {
            $check('storage', 'error', Str::limit($exception->getMessage(), 200));
        }

        $heartbeat = Cache::get(self::HEARTBEAT_KEY);
        $check('scheduler', $heartbeat && now()->diffInMinutes($heartbeat, true) <= 5 ? 'ok' : 'warn', $heartbeat ? (string) $heartbeat : 'no heartbeat');

        $pending = config('queue.default') === 'database' ? DB::table('jobs')->count() : null;
        $failed = DB::table('failed_jobs')->count();
        $check('queue', $failed > 0 ? 'warn' : 'ok', config('queue.default').($pending !== null ? " · pending: {$pending}" : '')." · failed: {$failed}");

        $check('mail', in_array(config('mail.default'), ['log', 'array'], true) ? 'warn' : 'ok', config('mail.default'));
        $check('stripe', $billing->isConfigured() ? 'ok' : 'warn', $billing->isConfigured() ? 'configured' : 'missing keys or prices');
        $check('stripe_webhook', filled(config('cashier.webhook.secret')) ? 'ok' : (config('billing.allow_unsigned_webhooks') ? 'warn' : 'error'), filled(config('cashier.webhook.secret')) ? 'signed' : 'no signing secret');
        $check('szamlazz', config('billing.szamlazz.enabled') && filled(config('billing.szamlazz.agent_key')) ? 'ok' : 'warn', config('billing.szamlazz.enabled') ? 'enabled' : 'disabled');
        $check('debug', config('app.debug') && app()->isProduction() ? 'error' : 'ok', config('app.env').' · debug '.(config('app.debug') ? 'on' : 'off'));

        return response()->json([
            'checks' => $checks,
            'versions' => ['php' => PHP_VERSION, 'laravel' => app()->version()],
            'failed_jobs' => DB::table('failed_jobs')->latest('failed_at')->limit(20)->get()->map(fn ($job) => [
                'uuid' => $job->uuid,
                'queue' => $job->queue,
                'job' => json_decode($job->payload, true)['displayName'] ?? null,
                'exception' => Str::limit(strtok((string) $job->exception, "\n"), 300),
                'failed_at' => $job->failed_at,
            ]),
            'recent_errors' => $this->recentErrors(),
        ]);
    }

    public function retryFailedJob(string $uuid, AuditLogger $audit): JsonResponse
    {
        abort_unless(DB::table('failed_jobs')->where('uuid', $uuid)->exists(), 404);
        Artisan::call('queue:retry', ['id' => [$uuid]]);
        $audit->log('admin.failed_job.retried', null, ['uuid' => $uuid], null, true);

        return response()->json(['ok' => true]);
    }

    public function forgetFailedJob(string $uuid, AuditLogger $audit): JsonResponse
    {
        abort_unless(DB::table('failed_jobs')->where('uuid', $uuid)->exists(), 404);
        Artisan::call('queue:forget', ['id' => $uuid]);
        $audit->log('admin.failed_job.deleted', null, ['uuid' => $uuid], null, true);

        return response()->json(['ok' => true]);
    }

    /**
     * Last error entries of the application log (only the message line, no stack traces or request data).
     *
     * @return list<array{at: string, level: string, message: string}>
     */
    protected function recentErrors(): array
    {
        $path = storage_path('logs/laravel.log');

        if (! is_file($path)) {
            return [];
        }

        $handle = fopen($path, 'r');
        fseek($handle, max(0, filesize($path) - 262144));
        $chunk = (string) stream_get_contents($handle);
        fclose($handle);

        preg_match_all('/^\[(\d{4}-\d{2}-\d{2}[ T][\d:.+-]+)\] \w+\.(ERROR|CRITICAL|ALERT|EMERGENCY): (.{0,300})/m', $chunk, $matches, PREG_SET_ORDER);

        return array_reverse(array_map(fn ($match) => ['at' => $match[1], 'level' => $match[2], 'message' => $match[3]], array_slice($matches, -30)));
    }
}
