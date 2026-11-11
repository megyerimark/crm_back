<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\PipelineResource;
use App\Models\Pipeline;
use App\Models\PipelineStage;
use App\Services\AuditLogger;
use App\Services\PlanService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class PipelineController extends Controller
{
    public function __construct(protected PlanService $plans, protected AuditLogger $audit) {}

    public function index(): JsonResponse
    {
        $pipelines = Pipeline::with(['stages' => fn ($q) => $q->withCount('deals')])->withCount('deals')->orderBy('position')->get();

        return response()->json(['data' => PipelineResource::collection($pipelines)]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->plans->ensureWithinLimit($this->workspace(), 'pipelines');
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'stages' => ['nullable', 'array', 'min:2', 'max:15'],
            'stages.*.name' => ['required', 'string', 'max:100'],
            'stages.*.type' => ['required', Rule::in(PipelineStage::TYPES)],
        ]);

        $pipeline = DB::transaction(function () use ($data): Pipeline {
            $pipeline = Pipeline::create(['name' => $data['name'], 'position' => Pipeline::max('position') + 1]);

            if (empty($data['stages'])) {
                $pipeline->createDefaultStages($this->workspace()->locale);
            } else {
                foreach ($data['stages'] as $position => $stage) {
                    $pipeline->stages()->create($stage + ['position' => $position, 'workspace_id' => $pipeline->workspace_id]);
                }
            }

            return $pipeline;
        });

        return response()->json(['data' => PipelineResource::make($pipeline->load('stages'))], 201);
    }

    /**
     * Renames the pipeline and synchronises its stages (create / rename / reorder / delete empty ones).
     */
    public function update(Request $request, Pipeline $pipeline): JsonResponse
    {
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'is_default' => ['sometimes', 'boolean'],
            'stages' => ['sometimes', 'array', 'min:2', 'max:15'],
            'stages.*.id' => ['nullable', 'integer'],
            'stages.*.name' => ['required', 'string', 'max:100'],
            'stages.*.type' => ['required', Rule::in(PipelineStage::TYPES)],
        ]);

        DB::transaction(function () use ($data, $pipeline): void {
            if (isset($data['name'])) {
                $pipeline->update(['name' => $data['name']]);
            }

            if (! empty($data['is_default'])) {
                Pipeline::whereKeyNot($pipeline->id)->update(['is_default' => false]);
                $pipeline->update(['is_default' => true]);
            }

            if (isset($data['stages'])) {
                $keep = [];

                foreach ($data['stages'] as $position => $stageData) {
                    $stage = isset($stageData['id']) ? $pipeline->stages()->find($stageData['id']) : null;
                    $attributes = ['name' => $stageData['name'], 'type' => $stageData['type'], 'position' => $position];

                    $stage ? $stage->update($attributes) : $stage = $pipeline->stages()->create($attributes + ['workspace_id' => $pipeline->workspace_id]);
                    $keep[] = $stage->id;
                }

                $removed = $pipeline->stages()->whereNotIn('id', $keep)->withCount('deals')->get();

                if ($removed->contains(fn ($stage) => $stage->deals_count > 0)) {
                    throw ValidationException::withMessages(['stages' => 'A stage that still has deals cannot be deleted.']);
                }

                $pipeline->stages()->whereNotIn('id', $keep)->delete();
            }
        });

        return response()->json(['data' => PipelineResource::make($pipeline->refresh()->load('stages'))]);
    }

    public function destroy(Pipeline $pipeline): JsonResponse
    {
        if (Pipeline::count() <= 1) {
            throw ValidationException::withMessages(['pipeline' => 'At least one pipeline is required.']);
        }

        $pipeline->delete();
        $this->audit->log('pipeline.deleted', $pipeline, ['name' => $pipeline->name]);

        if ($pipeline->is_default) {
            Pipeline::orderBy('position')->first()?->update(['is_default' => true]);
        }

        return response()->json(['ok' => true]);
    }
}
