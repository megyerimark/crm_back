<?php

namespace Tests\Feature;

use App\Models\Invitation;
use App\Models\User;
use App\Notifications\WorkspaceInvitation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TeamTest extends TestCase
{
    use RefreshDatabase;

    public function test_invitation_flow(): void
    {
        Notification::fake();
        $this->signIn('business');

        $this->postJson('/api/v1/team/invitations', ['email' => 'kollega@example.com', 'role' => 'member'])->assertCreated();

        $token = null;
        Notification::assertSentTo(new AnonymousNotifiable, WorkspaceInvitation::class, function (WorkspaceInvitation $notification) use (&$token) {
            $token = $notification->plainToken;

            return true;
        });

        $this->getJson("/api/v1/invitations/{$token}")->assertOk()->assertJsonPath('data.workspace', 'Teszt Kft.');

        $colleague = User::factory()->unverified()->create(['email' => 'kollega@example.com']);
        Sanctum::actingAs($colleague);
        $this->postJson("/api/v1/invitations/{$token}/accept")->assertOk()->assertJsonPath('data.role', 'member');

        $this->assertSame('member', $colleague->roleIn($this->workspace)?->value);
        $this->assertTrue($colleague->fresh()->hasVerifiedEmail(), 'accepting the e-mailed invitation verifies the address');
        $this->getJson("/api/v1/invitations/{$token}")->assertNotFound();
    }

    public function test_invitation_cannot_be_accepted_by_another_email(): void
    {
        Notification::fake();
        $this->signIn('business');
        $this->postJson('/api/v1/team/invitations', ['email' => 'kollega@example.com', 'role' => 'member'])->assertCreated();
        $invitation = Invitation::firstOrFail();
        $token = 'known-token';
        $invitation->forceFill(['token_hash' => hash('sha256', $token)])->save();

        Sanctum::actingAs(User::factory()->create());
        $this->postJson("/api/v1/invitations/{$token}/accept")->assertUnprocessable();
    }

    public function test_seat_limit_counts_pending_invitations(): void
    {
        Notification::fake();
        $this->signIn('business');

        foreach (range(1, 4) as $i) {
            $this->postJson('/api/v1/team/invitations', ['email' => "user{$i}@example.com", 'role' => 'member'])->assertCreated();
        }

        $this->postJson('/api/v1/team/invitations', ['email' => 'user5@example.com', 'role' => 'member'])->assertStatus(402);

        $this->deleteJson('/api/v1/team/invitations/'.Invitation::first()->id)->assertOk()->assertJsonPath('data.status', 'revoked');
        $this->postJson('/api/v1/team/invitations', ['email' => 'user5@example.com', 'role' => 'member'])->assertCreated();
    }

    public function test_owner_cannot_be_removed(): void
    {
        $this->signIn('business');

        $this->deleteJson("/api/v1/team/members/{$this->user->id}")->assertUnprocessable();
    }
}
