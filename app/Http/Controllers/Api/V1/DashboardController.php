<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\ActivityResource;
use App\Http\Resources\TaskResource;
use App\Models\Activity;
use App\Models\Client;
use App\Models\Deal;
use App\Models\Lead;
use App\Models\Pipeline;
use App\Models\Task;
use App\Services\PlanService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function __invoke(Request $request, PlanService $plans): JsonResponse
    {
        $workspace = $this->workspace();
        $since = now()->subDays(30);

        $pipeline = Pipeline::with('stages')->orderByDesc('is_default')->orderBy('position')->first();
        $stageTotals = $pipeline
            ? Deal::where('pipeline_id', $pipeline->id)->selectRaw('stage_id, count(*) as deals, coalesce(sum(value), 0) as total')->groupBy('stage_id')->get()->keyBy('stage_id')
            : collect();

        return response()->json([
            'stats' => [
                'clients_total' => Client::count(),
                'clients_active' => Client::where('status', 'active')->count(),
                'leads_new' => Lead::where('status', 'new')->count(),
                'leads_last_30_days' => Lead::where('created_at', '>=', $since)->count(),
                'tasks_open' => Task::open()->count(),
                'tasks_overdue' => Task::overdue()->count(),
                'deals_open_value' => (float) Deal::whereNull('closed_at')->sum('value'),
            ],
            'upcoming_tasks' => TaskResource::collection(
                Task::open()->with(['client', 'lead', 'assignee'])->whereNotNull('due_at')->where('due_at', '<=', now()->addDays(7))->orderBy('due_at')->limit(8)->get()
            ),
            'pipeline' => $pipeline ? [
                'id' => $pipeline->id,
                'name' => $pipeline->name,
                'stages' => $pipeline->stages->map(fn ($stage) => [
                    'id' => $stage->id,
                    'name' => $stage->name,
                    'type' => $stage->type,
                    'deals' => (int) ($stageTotals[$stage->id]->deals ?? 0),
                    'value' => (float) ($stageTotals[$stage->id]->total ?? 0),
                ]),
            ] : null,
            'acquisition' => $this->monthly(6),
            'lead_sources' => Lead::where('created_at', '>=', $since)
                ->selectRaw('coalesce(utm_source, source) as channel, count(*) as total')
                ->groupBy('channel')->orderByDesc('total')->limit(8)->get()
                ->map(fn ($row) => ['channel' => $row->channel, 'total' => (int) $row->total]),
            'recent_activities' => ActivityResource::collection(Activity::with(['user', 'subject'])->latest('occurred_at')->latest('id')->limit(10)->get()),
            'subscription' => $plans->summary($workspace),
        ]);
    }

    /**
     * New clients and leads per month (grouped in PHP to stay database agnostic, in the workspace timezone).
     *
     * @return list<array{month: string, clients: int, leads: int}>
     */
    protected function monthly(int $months): array
    {
        $timezone = $this->workspace()->timezone;
        $start = CarbonImmutable::now($timezone)->startOfMonth()->subMonths($months - 1);
        $series = [];

        for ($i = 0; $i < $months; $i++) {
            $series[$start->addMonths($i)->format('Y-m')] = ['month' => $start->addMonths($i)->format('Y-m'), 'clients' => 0, 'leads' => 0];
        }

        foreach (['clients' => Client::class, 'leads' => Lead::class] as $key => $model) {
            $model::where('created_at', '>=', $start->utc())->pluck('created_at')->each(function ($date) use (&$series, $key, $timezone): void {
                $month = $date->copy()->timezone($timezone)->format('Y-m');

                if (isset($series[$month])) {
                    $series[$month][$key]++;
                }
            });
        }

        return array_values($series);
    }
}
