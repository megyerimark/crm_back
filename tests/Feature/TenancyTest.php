<?php

namespace Tests\Feature;

use App\Enums\WorkspaceRole;
use App\Models\Client;
use App\Models\User;
use App\Services\WorkspaceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TenancyTest extends TestCase
{
    use RefreshDatabase;

    public function test_records_of_other_workspaces_are_not_visible(): void
    {
        $this->signIn();
        $other = app(WorkspaceService::class)->create(User::factory()->create(), ['name' => 'Másik']);
        $foreign = Client::factory()->create(['workspace_id' => $other->id]);
        $own = Client::factory()->create(['workspace_id' => $this->workspace->id]);

        $this->getJson('/api/v1/clients')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $own->id);
        $this->getJson("/api/v1/clients/{$foreign->id}")->assertNotFound();
        $this->patchJson("/api/v1/clients/{$foreign->id}", ['name' => 'Hacked'])->assertNotFound();
        $this->deleteJson("/api/v1/clients/{$foreign->id}")->assertNotFound();

        $this->assertSame($foreign->name, $foreign->fresh()->name);
    }

    public function test_header_for_a_workspace_the_user_is_not_member_of_is_rejected(): void
    {
        $this->signIn();
        $other = app(WorkspaceService::class)->create(User::factory()->create(), ['name' => 'Másik']);

        $this->withHeader('X-Workspace-Id', (string) $other->id)->getJson('/api/v1/clients')
            ->assertForbidden()->assertJsonPath('code', 'not_a_member');
    }

    public function test_foreign_ids_cannot_be_referenced_in_payloads(): void
    {
        $this->signIn();
        $other = app(WorkspaceService::class)->create(User::factory()->create(), ['name' => 'Másik']);
        $foreignClient = Client::factory()->create(['workspace_id' => $other->id]);

        $this->postJson('/api/v1/tasks', ['title' => 'Visszahívás', 'client_id' => $foreignClient->id])
            ->assertUnprocessable()->assertJsonValidationErrors('client_id');

        $this->postJson('/api/v1/clients', ['name' => 'Ügyfél', 'owner_id' => $other->owner_id])
            ->assertUnprocessable()->assertJsonValidationErrors('owner_id');
    }

    public function test_viewer_can_read_but_not_write(): void
    {
        $this->signIn('business');
        $viewer = $this->addMember(WorkspaceRole::Viewer);
        $this->actingAs($viewer, 'sanctum');

        $this->getJson('/api/v1/clients')->assertOk();
        $this->postJson('/api/v1/clients', ['name' => 'Új'])->assertForbidden()->assertJsonPath('code', 'read_only');
    }

    public function test_member_cannot_manage_team_or_settings(): void
    {
        $this->signIn('business');
        $member = $this->addMember(WorkspaceRole::Member);
        $this->actingAs($member, 'sanctum');

        $this->postJson('/api/v1/clients', ['name' => 'Új'])->assertCreated();
        $this->patchJson('/api/v1/workspace', ['name' => 'Átnevezve'])->assertForbidden();
        $this->postJson('/api/v1/team/invitations', ['email' => 'x@example.com', 'role' => 'member'])->assertForbidden();
    }

    public function test_suspended_workspace_is_locked(): void
    {
        $this->signIn();
        $this->workspace->forceFill(['suspended_at' => now()])->save();

        $this->getJson('/api/v1/clients')->assertStatus(423);
    }
}
