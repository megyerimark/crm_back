<?php

namespace App\Services;

use App\Enums\Plan;
use App\Exceptions\FeatureNotAvailable;
use App\Exceptions\PlanLimitExceeded;
use App\Models\AutomationRule;
use App\Models\Client;
use App\Models\CustomFieldDefinition;
use App\Models\Form;
use App\Models\FormSubmission;
use App\Models\Import;
use App\Models\Lead;
use App\Models\Pipeline;
use App\Models\Task;
use App\Models\Workspace;

/**
 * Resolves the effective plan of a workspace and enforces its limits server side.
 */
class PlanService
{
    /**
     * The plan whose limits currently apply: an active paid subscription wins,
     * then a running trial (Pro), otherwise Free. Expiry never deletes data.
     */
    public function effectivePlan(Workspace $workspace): Plan
    {
        $plan = Plan::tryFrom($workspace->plan) ?? Plan::Free;

        if ($plan->isPaid() && $this->hasActiveSubscription($workspace)) {
            return $plan;
        }

        if ($workspace->onTrial()) {
            return Plan::from(config('plans.trial_plan'));
        }

        return Plan::Free;
    }

    public function hasActiveSubscription(Workspace $workspace): bool
    {
        if ($workspace->subscription_status !== 'active') {
            return false;
        }

        return $workspace->subscription_ends_at === null || $workspace->subscription_ends_at->isFuture();
    }

    /**
     * @return array{name: string, price: int, currency: string, limits: array<string, int|null>, features: array<string, bool>}
     */
    public function definition(Plan $plan): array
    {
        return config('plans.plans.'.$plan->value);
    }

    public function limit(Workspace $workspace, string $key): ?int
    {
        $limit = $this->definition($this->effectivePlan($workspace))['limits'][$key] ?? null;

        return $limit ?? config('plans.fair_use.'.$key);
    }

    public function hasFeature(Workspace $workspace, string $feature): bool
    {
        return (bool) ($this->definition($this->effectivePlan($workspace))['features'][$feature] ?? false);
    }

    public function ensureFeature(Workspace $workspace, string $feature): void
    {
        if (! $this->hasFeature($workspace, $feature)) {
            throw new FeatureNotAvailable($feature);
        }
    }

    /**
     * Throws when adding $adding more items would exceed the limit.
     */
    public function ensureWithinLimit(Workspace $workspace, string $key, int $adding = 1): void
    {
        $limit = $this->limit($workspace, $key);

        if ($limit === null) {
            return;
        }

        if ($this->usageFor($workspace, $key) + $adding > $limit) {
            throw new PlanLimitExceeded($key, $limit);
        }
    }

    public function remaining(Workspace $workspace, string $key): ?int
    {
        $limit = $this->limit($workspace, $key);

        return $limit === null ? null : max(0, $limit - $this->usageFor($workspace, $key));
    }

    public function usageFor(Workspace $workspace, string $key): int
    {
        $id = $workspace->id;

        return match ($key) {
            'users' => $workspace->members()->count() + $workspace->invitations()->whereNull('accepted_at')->whereNull('revoked_at')->where('expires_at', '>', now())->count(),
            'clients' => Client::withoutGlobalScopes()->where('workspace_id', $id)->whereNull('deleted_at')->count(),
            'active_leads' => Lead::withoutGlobalScopes()->where('workspace_id', $id)->whereNull('deleted_at')->whereNotIn('status', ['converted', 'unqualified'])->count(),
            'forms' => Form::withoutGlobalScopes()->where('workspace_id', $id)->whereNull('deleted_at')->count(),
            'submissions_per_month' => FormSubmission::withoutGlobalScopes()->where('workspace_id', $id)->where('status', 'accepted')->where('created_at', '>=', now()->startOfMonth())->count(),
            'pipelines' => Pipeline::withoutGlobalScopes()->where('workspace_id', $id)->count(),
            'active_tasks' => Task::withoutGlobalScopes()->where('workspace_id', $id)->where('status', '!=', 'done')->count(),
            'automations' => AutomationRule::withoutGlobalScopes()->where('workspace_id', $id)->count(),
            'custom_fields' => CustomFieldDefinition::withoutGlobalScopes()->where('workspace_id', $id)->count(),
            'imports' => Import::withoutGlobalScopes()->where('workspace_id', $id)->where('status', 'completed')->count(),
            default => 0,
        };
    }

    /**
     * @return array<string, array{used: int, limit: int|null}>
     */
    public function usage(Workspace $workspace): array
    {
        $plan = $this->definition($this->effectivePlan($workspace));
        $usage = [];

        foreach (array_keys($plan['limits']) as $key) {
            if ($key === 'import_max_rows') {
                continue;
            }

            $usage[$key] = [
                'used' => $this->usageFor($workspace, $key),
                'limit' => $plan['limits'][$key],
            ];
        }

        return $usage;
    }

    /**
     * Summary used by the frontend to render plan state.
     *
     * @return array<string, mixed>
     */
    public function summary(Workspace $workspace): array
    {
        $effective = $this->effectivePlan($workspace);
        $definition = $this->definition($effective);

        return [
            'plan' => $workspace->plan,
            'effective_plan' => $effective->value,
            'on_trial' => $workspace->onTrial(),
            'trial_ends_at' => $workspace->trial_ends_at?->toIso8601String(),
            'subscription_status' => $workspace->subscription_status,
            'limits' => $definition['limits'],
            'features' => $definition['features'],
            'usage' => $this->usage($workspace),
        ];
    }

    /**
     * Describes which existing data would be over the limits of $target plan (downgrade notice).
     *
     * @return array<string, array{used: int, limit: int}>
     */
    public function overLimitsFor(Workspace $workspace, Plan $target): array
    {
        $over = [];

        foreach ($this->definition($target)['limits'] as $key => $limit) {
            if ($limit === null || $key === 'import_max_rows') {
                continue;
            }

            $used = $this->usageFor($workspace, $key);

            if ($used > $limit) {
                $over[$key] = ['used' => $used, 'limit' => $limit];
            }
        }

        return $over;
    }
}
