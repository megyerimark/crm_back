<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\DealResource;
use App\Models\Deal;
use App\Models\DealStageChange;
use App\Models\PipelineStage;
use App\Services\ActivityLogger;
use App\Services\AutomationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class DealController extends Controller
{
    public function __construct(protected ActivityLogger $activities, protected AutomationService $automations) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $query = Deal::query()->with(['client', 'lead', 'owner', 'stage']);

        $query->when($request->query('pipeline_id'), fn ($q, $id) => $q->where('pipeline_id', $id))
            ->when($request->query('client_id'), fn ($q, $id) => $q->where('client_id', $id))
            ->when($request->query('lead_id'), fn ($q, $id) => $q->where('lead_id', $id))
            ->when($request->query('owner_id'), fn ($q, $id) => $q->where('owner_id', $id));

        $this->applySearch($query, $request->query('search'), ['title']);

        return DealResource::collection($query->orderBy('position')->orderByDesc('id')->limit(1000)->get());
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request);
        $stage = PipelineStage::findOrFail($data['stage_id']);
        $data['pipeline_id'] = $stage->pipeline_id;
        $data['owner_id'] ??= $request->user()->id;
        $data['currency'] ??= $this->workspace()->currency;
        $data['value'] ??= 0;
        $data['position'] = (int) Deal::where('stage_id', $stage->id)->max('position') + 1;
        $data['closed_at'] = $stage->isClosed() ? now() : null;

        $deal = DB::transaction(function () use ($data, $stage, $request): Deal {
            $deal = Deal::create($data);
            DealStageChange::create(['deal_id' => $deal->id, 'to_stage_id' => $stage->id, 'user_id' => $request->user()->id]);

            return $deal;
        });

        return DealResource::make($deal->load('client', 'lead', 'owner', 'stage'))->response()->setStatusCode(201);
    }

    public function show(Deal $deal): DealResource
    {
        return DealResource::make($deal->load(['client', 'lead', 'owner', 'stage', 'stageChanges.fromStage', 'stageChanges.toStage', 'stageChanges.user']));
    }

    public function update(Request $request, Deal $deal): DealResource
    {
        $data = $this->validated($request, true);
        $newStageId = $data['stage_id'] ?? null;
        unset($data['stage_id']);

        $deal->update($data);

        if ($newStageId && (int) $newStageId !== $deal->stage_id) {
            $this->moveTo($deal, PipelineStage::findOrFail($newStageId), $request);
        }

        return DealResource::make($deal->refresh()->load('client', 'lead', 'owner', 'stage'));
    }

    /**
     * Kanban drag & drop: moves a deal to a stage/position and logs the stage change with a timestamp.
     */
    public function move(Request $request, Deal $deal): DealResource
    {
        $data = $request->validate([
            'stage_id' => ['required', 'integer', $this->ownedRule('pipeline_stages', false)],
            'position' => ['nullable', 'integer', 'min:0'],
        ]);

        $stage = PipelineStage::findOrFail($data['stage_id']);

        DB::transaction(function () use ($deal, $stage, $data, $request): void {
            if ($stage->id !== $deal->stage_id) {
                $this->moveTo($deal, $stage, $request);
            }

            $ids = Deal::where('stage_id', $stage->id)->whereKeyNot($deal->id)->orderBy('position')->orderByDesc('id')->pluck('id')->all();
            array_splice($ids, min($data['position'] ?? count($ids), count($ids)), 0, [$deal->id]);

            foreach ($ids as $position => $id) {
                Deal::whereKey($id)->update(['position' => $position]);
            }
        });

        return DealResource::make($deal->refresh()->load('client', 'lead', 'owner', 'stage'));
    }

    public function destroy(Deal $deal): JsonResponse
    {
        $deal->delete();

        return response()->json(['ok' => true]);
    }

    protected function moveTo(Deal $deal, PipelineStage $stage, Request $request): void
    {
        if ($stage->pipeline_id !== $deal->pipeline_id) {
            throw ValidationException::withMessages(['stage_id' => 'The stage belongs to a different pipeline.']);
        }

        $from = $deal->stage;
        $deal->forceFill(['stage_id' => $stage->id, 'closed_at' => $stage->isClosed() ? now() : null])->save();

        DealStageChange::create(['deal_id' => $deal->id, 'from_stage_id' => $from?->id, 'to_stage_id' => $stage->id, 'user_id' => $request->user()->id]);

        $title = __('crm.activity.stage_changed', ['from' => $from?->name, 'to' => $stage->name]);
        $this->activities->log($deal, 'stage_change', $title, null, ['from' => $from?->id, 'to' => $stage->id]);

        if ($deal->client) {
            $this->activities->log($deal->client, 'stage_change', $deal->title.': '.$title, null, ['deal_id' => $deal->id]);
        }

        if ($stage->type === 'won' || $stage->type === 'lost') {
            $this->automations->fire($this->workspace(), 'deal_'.$stage->type, $deal);
        }
    }

    /**
     * @return array<string, mixed>
     */
    protected function validated(Request $request, bool $updating = false): array
    {
        $required = $updating ? 'sometimes' : 'required';

        return $request->validate([
            'title' => [$required, 'string', 'max:255'],
            'stage_id' => [$required, 'integer', $this->ownedRule('pipeline_stages', false)],
            'client_id' => ['nullable', 'integer', $this->ownedRule('clients')],
            'lead_id' => ['nullable', 'integer', $this->ownedRule('leads')],
            'value' => ['nullable', 'numeric', 'min:0', 'max:999999999999'],
            'currency' => ['nullable', 'string', 'size:3'],
            'owner_id' => ['nullable', 'integer', $this->memberRule()],
            'expected_close_date' => ['nullable', 'date'],
        ]);
    }
}
