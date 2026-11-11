<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\AutomationRuleResource;
use App\Models\AutomationRule;
use App\Models\Task;
use App\Services\PlanService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

class AutomationRuleController extends Controller
{
    public function __construct(protected PlanService $plans) {}

    public function index(): AnonymousResourceCollection
    {
        return AutomationRuleResource::collection(AutomationRule::orderBy('id')->get());
    }

    public function store(Request $request): JsonResponse
    {
        $this->plans->ensureWithinLimit($this->workspace(), 'automations');
        $rule = AutomationRule::create($this->validated($request));

        return AutomationRuleResource::make($rule)->response()->setStatusCode(201);
    }

    public function update(Request $request, AutomationRule $automationRule): AutomationRuleResource
    {
        $automationRule->update($this->validated($request, true));

        return AutomationRuleResource::make($automationRule);
    }

    public function destroy(AutomationRule $automationRule): JsonResponse
    {
        $automationRule->delete();

        return response()->json(['ok' => true]);
    }

    /**
     * @return array<string, mixed>
     */
    protected function validated(Request $request, bool $updating = false): array
    {
        $required = $updating ? 'sometimes' : 'required';

        $data = $request->validate([
            'name' => [$required, 'string', 'max:255'],
            'trigger' => [$required, Rule::in(AutomationRule::TRIGGERS)],
            'action' => [$required, Rule::in(AutomationRule::ACTIONS)],
            'conditions' => ['nullable', 'array'],
            'conditions.source' => ['nullable', 'string', 'max:50'],
            'conditions.form_id' => ['nullable', 'integer'],
            'action_config' => ['nullable', 'array'],
            'action_config.title' => ['nullable', 'string', 'max:255'],
            'action_config.description' => ['nullable', 'string', 'max:2000'],
            'action_config.due_in_days' => ['nullable', 'integer', 'min:0', 'max:365'],
            'action_config.priority' => ['nullable', Rule::in(Task::PRIORITIES)],
            'action_config.assignee_id' => ['nullable', 'integer', $this->memberRule()],
            'action_config.user_ids' => ['nullable', 'array'],
            'action_config.user_ids.*' => ['integer', $this->memberRule()],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        if (($data['action'] ?? null) === 'assign_owner') {
            $this->plans->ensureFeature($this->workspace(), 'auto_assignment');
        }

        return $data;
    }
}
