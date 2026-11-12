<?php

namespace App\Console\Commands;

use App\Models\Task;
use App\Models\Workspace;
use App\Notifications\TaskReminder;
use App\Services\PlanService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('crm:send-task-reminders {--minutes=60 : Remind about tasks due within this many minutes}')]
#[Description('Send e-mail reminders for tasks that are due soon (Pro and Business plans)')]
class SendTaskReminders extends Command
{
    public function handle(PlanService $plans): int
    {
        $sent = 0;

        Task::withoutGlobalScopes()
            ->with('assignee')
            ->where('status', '!=', 'done')
            ->whereNull('reminder_sent_at')
            ->whereNotNull('assignee_id')
            ->whereBetween('due_at', [now(), now()->addMinutes((int) $this->option('minutes'))])
            ->orderBy('workspace_id')
            ->chunkById(200, function ($tasks) use ($plans, &$sent): void {
                $workspaces = Workspace::whereIn('id', $tasks->pluck('workspace_id')->unique())->get()->keyBy('id');

                foreach ($tasks as $task) {
                    $workspace = $workspaces[$task->workspace_id] ?? null;

                    if (! $workspace || ! $task->assignee || ! $task->assignee->notify_task_reminders || ! $plans->hasFeature($workspace, 'email_reminders')) {
                        continue;
                    }

                    $task->assignee->notify((new TaskReminder($task, $task->assignee->timezone ?: $workspace->timezone))->locale($task->assignee->preferredLocale()));
                    $task->forceFill(['reminder_sent_at' => now()])->saveQuietly();
                    $sent++;
                }
            });

        $this->info("Sent {$sent} reminders.");

        return self::SUCCESS;
    }
}
