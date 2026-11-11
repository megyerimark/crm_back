<?php

namespace App\Services;

use App\Models\AutomationRule;
use App\Models\Client;
use App\Models\Deal;
use App\Models\Lead;
use App\Models\Task;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Model;

/**
 * Executes simple "when X happens, do Y" rules (Pro: create tasks, Business: also auto assignment).
 */
class AutomationService
{
    public function __construct(protected PlanService $plans) {}

    public function fire(Workspace $workspace, string $trigger, Model $subject): void
    {
        if ($this->plans->limit($workspace, 'automations') === 0) {
            return;
        }

        $rules = AutomationRule::withoutGlobalScopes()
            ->where('workspace_id', $workspace->id)
            ->where('trigger', $trigger)
            ->where('is_active', true)
            ->orderBy('id')
            ->get();

        foreach ($rules as $rule) {
            if (! $this->matches($rule, $subject)) {
                continue;
            }

            match ($rule->action) {
                'create_task' => $this->createTask($workspace, $rule, $subject),
                'assign_owner' => $this->assignOwner($workspace, $rule, $subject),
                default => null,
            };

            $rule->increment('runs_count');
        }
    }

    protected function matches(AutomationRule $rule, Model $subject): bool
    {
        foreach ($rule->conditions ?? [] as $field => $expected) {
            if ($expected === null || $expected === '') {
                continue;
            }

            if ((string) $subject->getAttribute($field) !== (string) $expected) {
                return false;
            }
        }

        return true;
    }

    protected function createTask(Workspace $workspace, AutomationRule $rule, Model $subject): void
    {
        $config = $rule->action_config ?? [];
        $assignee = $config['assignee_id'] ?? $subject->getAttribute('owner_id');

        Task::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'title' => $this->interpolate($config['title'] ?? $rule->name, $subject),
            'description' => $config['description'] ?? null,
            'priority' => $config['priority'] ?? 'normal',
            'status' => 'open',
            'due_at' => now()->addDays((int) ($config['due_in_days'] ?? 1)),
            'assignee_id' => $assignee,
            'client_id' => $subject instanceof Client ? $subject->id : $subject->getAttribute('client_id'),
            'lead_id' => $subject instanceof Lead ? $subject->id : $subject->getAttribute('lead_id'),
            'deal_id' => $subject instanceof Deal ? $subject->id : null,
        ]);
    }

    /**
     * Round-robin assignment among the configured users (Business feature).
     */
    protected function assignOwner(Workspace $workspace, AutomationRule $rule, Model $subject): void
    {
        if (! $this->plans->hasFeature($workspace, 'auto_assignment') || $subject->getAttribute('owner_id')) {
            return;
        }

        $memberIds = $workspace->members()->pluck('users.id')->all();
        $candidates = array_values(array_intersect($rule->action_config['user_ids'] ?? $memberIds, $memberIds));

        if ($candidates === []) {
            return;
        }

        $subject->forceFill(['owner_id' => $candidates[$rule->runs_count % count($candidates)]])->save();
    }

    protected function interpolate(string $text, Model $subject): string
    {
        return strtr($text, [
            '{name}' => (string) ($subject->getAttribute('name') ?? $subject->getAttribute('title')),
            '{email}' => (string) $subject->getAttribute('email'),
        ]);
    }
}
