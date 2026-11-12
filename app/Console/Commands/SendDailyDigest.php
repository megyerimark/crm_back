<?php

namespace App\Console\Commands;

use App\Models\Task;
use App\Models\User;
use App\Notifications\DailyDigest;
use App\Services\PlanService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('crm:send-daily-digest {--hour=7 : Local hour at which the digest is sent} {--force : Ignore the local hour (testing)}')]
#[Description("E-mail every user a morning summary of today's and overdue tasks (plans with e-mail reminders)")]
class SendDailyDigest extends Command
{
    public function handle(PlanService $plans): int
    {
        $sent = 0;

        User::query()
            ->where('notify_daily_digest', true)
            ->whereNotNull('email_verified_at')
            ->with('workspaces')
            ->chunkById(200, function ($users) use ($plans, &$sent): void {
                foreach ($users as $user) {
                    $timezone = $user->timezone ?: ($user->workspaces->first()?->timezone ?? 'Europe/Budapest');
                    $now = CarbonImmutable::now($timezone);

                    if (! $this->option('force') && $now->hour !== (int) $this->option('hour')) {
                        continue;
                    }

                    if ($user->last_digest_sent_at && $user->last_digest_sent_at->timezone($timezone)->isSameDay($now)) {
                        continue;
                    }

                    $groups = [];

                    foreach ($user->workspaces as $workspace) {
                        if ($workspace->suspended_at || ! $plans->hasFeature($workspace, 'email_reminders')) {
                            continue;
                        }

                        $tasks = Task::withoutGlobalScopes()
                            ->with(['client', 'lead'])
                            ->where('workspace_id', $workspace->id)
                            ->where('assignee_id', $user->id)
                            ->where('status', '!=', 'done')
                            ->whereNotNull('due_at')
                            ->where('due_at', '<=', $now->endOfDay()->utc())
                            ->orderBy('due_at')
                            ->limit(20)
                            ->get();

                        if ($tasks->isNotEmpty()) {
                            $groups[] = [
                                'workspace' => $workspace->name,
                                'tasks' => $tasks->map(fn (Task $task) => [
                                    'title' => $task->title,
                                    'related' => $task->client?->name ?? $task->lead?->name,
                                    'due' => $task->due_at->timezone($timezone)->format($task->due_at->timezone($timezone)->isSameDay($now) ? 'H:i' : 'Y-m-d H:i'),
                                    'overdue' => $task->due_at->isPast(),
                                ])->all(),
                            ];
                        }
                    }

                    if ($groups === []) {
                        continue;
                    }

                    $user->notify(new DailyDigest($groups));
                    $user->forceFill(['last_digest_sent_at' => now()])->saveQuietly();
                    $sent++;
                }
            });

        $this->info("Sent {$sent} daily digests.");

        return self::SUCCESS;
    }
}
