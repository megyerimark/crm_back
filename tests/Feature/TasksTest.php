<?php

namespace Tests\Feature;

use App\Models\Task;
use App\Notifications\TaskReminder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class TasksTest extends TestCase
{
    use RefreshDatabase;

    public function test_task_lifecycle_and_calendar_range(): void
    {
        $this->signIn();

        $task = $this->postJson('/api/v1/tasks', ['title' => 'Ajánlat küldése', 'due_at' => now()->addDays(2)->toIso8601String(), 'priority' => 'high'])
            ->assertCreated()->json('data');

        $this->patchJson("/api/v1/tasks/{$task['id']}", ['status' => 'done'])->assertOk()->assertJsonPath('data.status', 'done');
        $this->assertNotNull(Task::find($task['id'])->completed_at);

        $this->getJson('/api/v1/tasks?from='.now()->toDateString().'&to='.now()->addWeek()->toDateString())
            ->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_free_plan_limits_active_tasks(): void
    {
        $this->signIn('free');
        Task::factory()->count(20)->create(['workspace_id' => $this->workspace->id]);

        $this->postJson('/api/v1/tasks', ['title' => 'Túl sok'])->assertStatus(402);
    }

    public function test_email_reminders_are_sent_on_paid_plans_only(): void
    {
        Notification::fake();
        $this->signIn('pro');
        Task::factory()->create(['workspace_id' => $this->workspace->id, 'assignee_id' => $this->user->id, 'due_at' => now()->addMinutes(30)]);

        $this->artisan('crm:send-task-reminders')->assertSuccessful();
        $this->artisan('crm:send-task-reminders')->assertSuccessful();

        Notification::assertSentToTimes($this->user, TaskReminder::class, 1);

        $this->workspace->forceFill(['plan' => 'free', 'subscription_status' => null])->save();
        Task::factory()->create(['workspace_id' => $this->workspace->id, 'assignee_id' => $this->user->id, 'due_at' => now()->addMinutes(30)]);
        $this->artisan('crm:send-task-reminders')->assertSuccessful();

        Notification::assertSentToTimes($this->user, TaskReminder::class, 1);
    }
}
