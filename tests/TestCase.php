<?php

namespace Tests;

use App\Enums\WorkspaceRole;
use App\Models\User;
use App\Models\Workspace;
use App\Services\WorkspaceService;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Laravel\Sanctum\Sanctum;

abstract class TestCase extends BaseTestCase
{
    protected User $user;

    protected Workspace $workspace;

    /**
     * Creates a user with a workspace (default pipeline included) and authenticates as them.
     *
     * @param  'free'|'trial'|'pro'|'business'  $plan
     */
    protected function signIn(string $plan = 'trial', ?User $user = null): User
    {
        $this->user = $user ?? User::factory()->create();
        $this->workspace = app(WorkspaceService::class)->create($this->user, ['name' => 'Teszt Kft.']);

        $this->workspace->forceFill(match ($plan) {
            'free' => ['trial_ends_at' => now()->subDay()],
            'trial' => [],
            default => ['plan' => $plan, 'subscription_status' => 'active', 'trial_ends_at' => null],
        })->save();

        Sanctum::actingAs($this->user);
        $this->withHeader('X-Workspace-Id', (string) $this->workspace->id);

        return $this->user;
    }

    protected function addMember(WorkspaceRole $role, ?Workspace $workspace = null): User
    {
        $member = User::factory()->create();
        ($workspace ?? $this->workspace)->members()->attach($member->id, ['role' => $role->value]);

        return $member;
    }
}
