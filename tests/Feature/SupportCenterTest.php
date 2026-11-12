<?php

namespace Tests\Feature;

use App\Enums\WorkspaceRole;
use App\Models\SupportTicket;
use App\Models\User;
use App\Notifications\SupportTicketCreated;
use App\Notifications\SupportTicketReplied;
use App\Services\WorkspaceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SupportCenterTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, string>
     */
    protected function ticket(): array
    {
        return ['subject' => 'Nem érkezik meg az űrlap', 'category' => 'bug', 'message' => 'A weboldalról beküldött űrlap nem jelenik meg.'];
    }

    public function test_ticket_priority_follows_the_plan_and_staff_is_notified(): void
    {
        Notification::fake();
        $admin = User::factory()->superAdmin()->create();

        $this->signIn('business');
        $this->postJson('/api/v1/support/tickets', $this->ticket())->assertCreated()->assertJsonPath('data.priority', 'high')->assertJsonPath('data.plan', 'business');
        Notification::assertSentTo($admin, SupportTicketCreated::class);

        $this->signIn('free', User::factory()->create());
        $this->postJson('/api/v1/support/tickets', $this->ticket())->assertCreated()->assertJsonPath('data.priority', 'low');
    }

    public function test_staff_queue_is_ordered_by_priority_and_reply_notifies_the_customer(): void
    {
        Notification::fake();
        $this->signIn('free');
        $low = $this->postJson('/api/v1/support/tickets', $this->ticket())->json('data.id');
        $customer = $this->user;

        $this->signIn('business', User::factory()->create());
        $high = $this->postJson('/api/v1/support/tickets', $this->ticket())->json('data.id');

        Sanctum::actingAs(User::factory()->superAdmin()->create());
        $this->getJson('/api/v1/admin/support/tickets')->assertOk()->assertJsonPath('data.0.id', $high)->assertJsonPath('data.1.id', $low);

        $this->postJson("/api/v1/admin/support/tickets/{$low}/messages", ['body' => 'Megnéztük, javítottuk.'])->assertOk()->assertJsonPath('data.status', 'answered');
        Notification::assertSentTo($customer, SupportTicketReplied::class);

        Sanctum::actingAs($customer);
        $this->withHeader('X-Workspace-Id', (string) $customer->current_workspace_id)
            ->getJson("/api/v1/support/tickets/{$low}")->assertOk()->assertJsonPath('data.messages.1.is_staff', true);
    }

    public function test_tickets_are_isolated_between_workspaces_and_members(): void
    {
        Notification::fake();
        $this->signIn('business');
        $ownerTicket = $this->postJson('/api/v1/support/tickets', $this->ticket())->json('data.id');

        $member = $this->addMember(WorkspaceRole::Member);
        Sanctum::actingAs($member);
        $this->getJson('/api/v1/support/tickets')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson("/api/v1/support/tickets/{$ownerTicket}")->assertNotFound();
        $this->postJson("/api/v1/support/tickets/{$ownerTicket}/messages", ['body' => 'x'])->assertNotFound();

        $stranger = User::factory()->create();
        $other = app(WorkspaceService::class)->create($stranger, ['name' => 'Másik']);
        Sanctum::actingAs($stranger);
        $this->withHeader('X-Workspace-Id', (string) $other->id)->getJson("/api/v1/support/tickets/{$ownerTicket}")->assertNotFound();
        $this->withHeader('X-Workspace-Id', (string) $other->id)->postJson("/api/v1/support/tickets/{$ownerTicket}/close")->assertNotFound();
        $this->assertSame('open', SupportTicket::find($ownerTicket)->status);
    }

    public function test_closed_ticket_cannot_be_replied(): void
    {
        Notification::fake();
        $this->signIn();
        $id = $this->postJson('/api/v1/support/tickets', $this->ticket())->json('data.id');

        $this->postJson("/api/v1/support/tickets/{$id}/close")->assertOk()->assertJsonPath('data.status', 'closed');
        $this->postJson("/api/v1/support/tickets/{$id}/messages", ['body' => 'még valami'])->assertUnprocessable();
    }
}
