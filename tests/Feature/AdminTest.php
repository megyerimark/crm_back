<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AdminTest extends TestCase
{
    use RefreshDatabase;

    public function test_workspace_admin_is_not_super_admin(): void
    {
        $this->signIn('business');

        $this->getJson('/api/v1/admin/stats')->assertForbidden();
    }

    public function test_super_admin_manages_plans_and_actions_are_audited(): void
    {
        $this->signIn();
        Sanctum::actingAs(User::factory()->superAdmin()->create());

        $this->getJson('/api/v1/admin/stats')->assertOk()->assertJsonPath('workspaces', 1);
        $this->getJson('/api/v1/admin/workspaces?search=Teszt')->assertOk()->assertJsonPath('data.0.id', $this->workspace->id);

        $this->patchJson("/api/v1/admin/workspaces/{$this->workspace->id}", ['plan' => 'business', 'subscription_status' => 'active'])
            ->assertOk()->assertJsonPath('data.effective_plan', 'business');

        $this->assertTrue(AuditLog::where('action', 'admin.workspace.updated')->where('is_admin_action', true)->exists());
        $this->assertTrue(AuditLog::where('action', 'admin.stats.viewed')->exists());
    }
}
