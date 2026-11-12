<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\Plan;
use App\Http\Controllers\Controller;
use App\Models\SupportTicket;
use App\Models\User;
use App\Models\Workspace;
use App\Services\PlanService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Platform analytics: sign-ups, active users, plan mix, trial conversion, MRR and churn.
 */
class AnalyticsController extends Controller
{
    public function __invoke(Request $request, PlanService $plans): JsonResponse
    {
        $days = min(365, max(7, $request->integer('days', 30)));
        $from = CarbonImmutable::today()->subDays($days - 1);

        $signups = User::where('created_at', '>=', $from)->selectRaw('date(created_at) as day, count(*) as total')->groupBy('day')->pluck('total', 'day');
        $active = DB::table('user_activity_days')->where('day', '>=', $from->toDateString())->selectRaw('day, count(*) as total')->groupBy('day')->pluck('total', 'day');

        $series = [];
        for ($i = 0; $i < $days; $i++) {
            $day = $from->addDays($i)->toDateString();
            $series[] = ['day' => $day, 'signups' => (int) ($signups[$day] ?? 0), 'active' => (int) ($active[$day] ?? 0)];
        }

        $distinctSince = fn (int $back) => DB::table('user_activity_days')->where('day', '>=', CarbonImmutable::today()->subDays($back - 1)->toDateString())->distinct()->count('user_id');

        $paidWorkspaces = Workspace::where('subscription_status', 'active')->whereIn('plan', [Plan::Pro->value, Plan::Business->value])
            ->where(fn ($q) => $q->whereNull('subscription_ends_at')->orWhere('subscription_ends_at', '>', now()))->get();

        $mrr = $paidWorkspaces->sum(function (Workspace $workspace) use ($plans) {
            $prices = $plans->definition(Plan::from($workspace->plan))['prices'];

            return $workspace->billing_interval === 'yearly' ? $prices['yearly'] / 12 : $prices['monthly'];
        });

        /* Trial conversion: workspaces whose 14-day trial window has passed, created in the period. */
        $cohort = Workspace::where('created_at', '>=', $from)->where('created_at', '<=', now()->subDays((int) config('plans.trial_days')))->get();
        $converted = $cohort->filter(fn (Workspace $workspace) => $workspace->subscription_status === 'active')->count();

        return response()->json([
            'days' => $days,
            'series' => $series,
            'users' => [
                'total' => User::count(),
                'signups' => (int) $signups->sum(),
                'dau' => $distinctSince(1),
                'wau' => $distinctSince(7),
                'mau' => $distinctSince(30),
                'unverified' => User::whereNull('email_verified_at')->count(),
                'two_factor' => User::whereNotNull('two_factor_confirmed_at')->count(),
            ],
            'workspaces' => [
                'total' => Workspace::count(),
                'on_trial' => Workspace::where('trial_ends_at', '>', now())->where(fn ($q) => $q->whereNull('subscription_status')->orWhere('subscription_status', '!=', 'active'))->count(),
                'paid' => $paidWorkspaces->count(),
                'paid_by_plan' => $paidWorkspaces->countBy('plan'),
                'suspended' => Workspace::whereNotNull('suspended_at')->count(),
            ],
            'revenue' => [
                'mrr' => (int) round($mrr),
                'arr' => (int) round($mrr * 12),
                'currency' => 'HUF',
                'churned_30d' => Workspace::where('subscription_status', 'canceled')->where('subscription_ends_at', '>=', now()->subDays(30))->count(),
            ],
            'conversion' => [
                'cohort' => $cohort->count(),
                'converted' => $converted,
                'rate' => $cohort->count() ? round($converted / $cohort->count() * 100, 1) : 0,
            ],
            'support' => [
                'open' => SupportTicket::where('status', 'open')->count(),
                'open_by_priority' => SupportTicket::where('status', 'open')->selectRaw('priority, count(*) as total')->groupBy('priority')->pluck('total', 'priority'),
            ],
        ]);
    }
}
