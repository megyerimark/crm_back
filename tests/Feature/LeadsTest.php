<?php

namespace Tests\Feature;

use App\Models\Activity;
use App\Models\AutomationRule;
use App\Models\Client;
use App\Models\Lead;
use App\Models\Task;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LeadsTest extends TestCase
{
    use RefreshDatabase;

    public function test_lead_is_not_a_client_until_converted(): void
    {
        $this->signIn();

        $lead = $this->postJson('/api/v1/leads', ['name' => 'Nagy Péter', 'email' => 'peter@example.com', 'phone' => '+36 30 123 4567'])
            ->assertCreated()->assertJsonPath('data.status', 'new')->json('data');

        $this->assertSame(0, Client::count());
        $this->assertSame($this->user->id, $lead['owner_id']);
    }

    public function test_conversion_creates_client_and_keeps_history(): void
    {
        $this->signIn();
        $lead = Lead::factory()->create(['workspace_id' => $this->workspace->id, 'company' => 'Klíma Bt.']);

        $this->postJson('/api/v1/activities', ['subject_type' => 'lead', 'subject_id' => $lead->id, 'type' => 'call', 'body' => 'Visszahívást kért'])
            ->assertCreated();
        $task = Task::factory()->create(['workspace_id' => $this->workspace->id, 'lead_id' => $lead->id]);

        $this->assertSame('contacted', $lead->fresh()->status);

        $response = $this->postJson("/api/v1/leads/{$lead->id}/convert")->assertOk();
        $clientId = $response->json('client.id');

        $response->assertJsonPath('client.type', 'company')->assertJsonPath('lead.status', 'converted')->assertJsonPath('lead.client_id', $clientId);
        $this->assertSame($clientId, $task->fresh()->client_id);

        $clientTimeline = Activity::where('subject_type', 'client')->where('subject_id', $clientId)->pluck('type')->all();
        $this->assertContains('call', $clientTimeline);
        $this->assertContains('conversion', $clientTimeline);
        $this->assertDatabaseHas('audit_logs', ['action' => 'lead.converted', 'auditable_id' => $lead->id]);

        $this->postJson("/api/v1/leads/{$lead->id}/convert")->assertUnprocessable();
    }

    public function test_free_plan_active_lead_limit(): void
    {
        $this->signIn('free');
        Lead::factory()->count(25)->create(['workspace_id' => $this->workspace->id]);

        $this->postJson('/api/v1/leads', ['name' => 'Huszonhatodik'])->assertStatus(402);

        Lead::query()->limit(1)->update(['status' => 'unqualified']);
        $this->postJson('/api/v1/leads', ['name' => 'Most már mehet'])->assertCreated();
    }

    public function test_automation_creates_follow_up_task_for_new_lead(): void
    {
        $this->signIn('pro');

        $this->postJson('/api/v1/automations', [
            'name' => 'Visszahívás',
            'trigger' => 'lead_created',
            'action' => 'create_task',
            'action_config' => ['title' => 'Hívd vissza: {name}', 'due_in_days' => 1, 'priority' => 'high'],
        ])->assertCreated();

        $this->postJson('/api/v1/leads', ['name' => 'Szabó Éva'])->assertCreated();

        $task = Task::firstOrFail();
        $this->assertSame('Hívd vissza: Szabó Éva', $task->title);
        $this->assertSame('high', $task->priority);
        $this->assertSame(1, AutomationRule::first()->runs_count);
    }

    public function test_round_robin_assignment_is_business_only(): void
    {
        $this->signIn('pro');

        $this->postJson('/api/v1/automations', ['name' => 'Kiosztás', 'trigger' => 'lead_created', 'action' => 'assign_owner'])
            ->assertStatus(402);
    }
}
