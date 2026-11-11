<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\Deal;
use App\Models\Form;
use App\Models\FormSubmission;
use App\Models\Lead;
use App\Models\Task;
use App\Services\PlanService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ReportController extends Controller
{
    public function __invoke(Request $request, PlanService $plans): JsonResponse
    {
        $workspace = $this->workspace();
        $request->validate(['from' => ['nullable', 'date'], 'to' => ['nullable', 'date']]);

        $timezone = $workspace->timezone;
        $from = $request->filled('from') ? CarbonImmutable::parse($request->query('from'), $timezone)->startOfDay() : CarbonImmutable::now($timezone)->subMonths(11)->startOfMonth();
        $to = $request->filled('to') ? CarbonImmutable::parse($request->query('to'), $timezone)->endOfDay() : CarbonImmutable::now($timezone)->endOfDay();
        $range = [$from->utc(), $to->utc()];

        $leadsTotal = Lead::whereBetween('created_at', $range)->count();
        $leadsConverted = Lead::whereBetween('created_at', $range)->whereNotNull('converted_at')->count();

        $deals = Deal::query()->join('pipeline_stages', 'pipeline_stages.id', '=', 'deals.stage_id')
            ->whereBetween('deals.created_at', $range)
            ->selectRaw('pipeline_stages.type as type, count(*) as total, coalesce(sum(deals.value), 0) as value')
            ->groupBy('pipeline_stages.type')->get()->keyBy('type');

        $tasksTotal = Task::whereBetween('created_at', $range)->count();
        $tasksDone = Task::whereBetween('created_at', $range)->where('status', 'done')->count();

        $report = [
            'range' => ['from' => $from->toDateString(), 'to' => $to->toDateString()],
            'monthly' => $this->monthly($from, $to, $timezone),
            'conversion' => [
                'leads' => $leadsTotal,
                'converted' => $leadsConverted,
                'rate' => $leadsTotal > 0 ? round($leadsConverted / $leadsTotal * 100, 1) : 0,
            ],
            'deals' => collect(['open', 'won', 'lost'])->mapWithKeys(fn ($type) => [$type => [
                'count' => (int) ($deals[$type]->total ?? 0),
                'value' => (float) ($deals[$type]->value ?? 0),
            ]]),
            'tasks' => [
                'total' => $tasksTotal,
                'done' => $tasksDone,
                'rate' => $tasksTotal > 0 ? round($tasksDone / $tasksTotal * 100, 1) : 0,
            ],
            'locked' => [],
        ];

        if ($plans->hasFeature($workspace, 'advanced_reports')) {
            $report['forms'] = Form::withTrashed()->get()->map(function (Form $form) use ($range) {
                $submissions = FormSubmission::where('form_id', $form->id)->whereBetween('created_at', $range);

                return [
                    'id' => $form->id,
                    'name' => $form->name,
                    'submissions' => (clone $submissions)->where('status', 'accepted')->count(),
                    'spam' => (clone $submissions)->where('status', 'spam')->count(),
                    'leads' => Lead::where('form_id', $form->id)->whereBetween('created_at', $range)->count(),
                    'converted' => Lead::where('form_id', $form->id)->whereBetween('created_at', $range)->whereNotNull('converted_at')->count(),
                ];
            })->values();

            $report['sources'] = Lead::whereBetween('created_at', $range)
                ->selectRaw('coalesce(utm_source, source) as channel, utm_medium as medium, utm_campaign as campaign, count(*) as leads, sum(case when converted_at is not null then 1 else 0 end) as converted')
                ->groupBy('channel', 'medium', 'campaign')->orderByDesc('leads')->limit(50)->get()
                ->map(fn ($row) => [
                    'channel' => $row->channel,
                    'medium' => $row->medium,
                    'campaign' => $row->campaign,
                    'leads' => (int) $row->leads,
                    'converted' => (int) $row->converted,
                    'rate' => $row->leads > 0 ? round($row->converted / $row->leads * 100, 1) : 0,
                ]);
        } else {
            $report['locked'][] = 'advanced_reports';
        }

        if ($plans->hasFeature($workspace, 'team_reports')) {
            $report['team'] = $workspace->members()->orderBy('name')->get()->map(fn ($member) => [
                'id' => $member->id,
                'name' => $member->name,
                'role' => $member->pivot->role,
                'tasks_done' => Task::where('assignee_id', $member->id)->where('status', 'done')->whereBetween('completed_at', $range)->count(),
                'tasks_open' => Task::where('assignee_id', $member->id)->open()->count(),
                'tasks_overdue' => Task::where('assignee_id', $member->id)->overdue()->count(),
                'leads_converted' => Lead::where('converted_by', $member->id)->whereBetween('converted_at', $range)->count(),
                'deals_won' => Deal::where('owner_id', $member->id)->whereHas('stage', fn ($q) => $q->where('type', 'won'))->whereBetween('closed_at', $range)->count(),
                'deals_won_value' => (float) Deal::where('owner_id', $member->id)->whereHas('stage', fn ($q) => $q->where('type', 'won'))->whereBetween('closed_at', $range)->sum('value'),
            ]);
        } else {
            $report['locked'][] = 'team_reports';
        }

        return response()->json($report);
    }

    /**
     * @return list<array{month: string, clients: int, leads: int}>
     */
    protected function monthly(CarbonImmutable $from, CarbonImmutable $to, string $timezone): array
    {
        $series = [];
        $cursor = $from->startOfMonth();

        while ($cursor <= $to && count($series) < 36) {
            $series[$cursor->format('Y-m')] = ['month' => $cursor->format('Y-m'), 'clients' => 0, 'leads' => 0];
            $cursor = $cursor->addMonth();
        }

        foreach (['clients' => Client::class, 'leads' => Lead::class] as $key => $model) {
            $model::whereBetween('created_at', [$from->utc(), $to->utc()])->pluck('created_at')->each(function ($date) use (&$series, $key, $timezone): void {
                $month = $date->copy()->timezone($timezone)->format('Y-m');

                if (isset($series[$month])) {
                    $series[$month][$key]++;
                }
            });
        }

        return array_values($series);
    }
}
