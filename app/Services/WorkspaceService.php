<?php

namespace App\Services;

use App\Enums\WorkspaceRole;
use App\Models\Pipeline;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class WorkspaceService
{
    /**
     * Creates a workspace with the owner membership, a 14 day Pro trial and the default pipeline.
     *
     * @param  array{name: string, country?: string, timezone?: string, currency?: string, locale?: string, intended_plan?: string|null, billing_interval?: string|null}  $attributes
     */
    public function create(User $owner, array $attributes): Workspace
    {
        return DB::transaction(function () use ($owner, $attributes): Workspace {
            $workspace = Workspace::create([
                'name' => $attributes['name'],
                'slug' => $this->uniqueSlug($attributes['name']),
                'owner_id' => $owner->id,
                'country' => $attributes['country'] ?? 'HU',
                'timezone' => $attributes['timezone'] ?? 'Europe/Budapest',
                'currency' => $attributes['currency'] ?? 'HUF',
                'locale' => $attributes['locale'] ?? $owner->preferredLocale(),
                'plan' => 'free',
                'trial_ends_at' => now()->addDays((int) config('plans.trial_days')),
                'intended_plan' => $attributes['intended_plan'] ?? null,
                'billing_interval' => $attributes['billing_interval'] ?? null,
            ]);

            $workspace->members()->attach($owner->id, ['role' => WorkspaceRole::Owner->value]);

            $pipeline = Pipeline::withoutGlobalScopes()->create([
                'workspace_id' => $workspace->id,
                'name' => trans('crm.default_pipeline', [], $workspace->locale),
                'is_default' => true,
                'position' => 0,
            ]);
            $pipeline->createDefaultStages($workspace->locale);

            $owner->forceFill(['current_workspace_id' => $workspace->id])->save();

            return $workspace;
        });
    }

    protected function uniqueSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'workspace';
        $slug = $base;

        while (Workspace::where('slug', $slug)->exists()) {
            $slug = $base.'-'.Str::lower(Str::random(5));
        }

        return $slug;
    }
}
