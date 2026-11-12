<?php

namespace App\Console\Commands;

use App\Models\Workspace;
use App\Notifications\TrialEndingSoon;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('crm:send-trial-reminders')]
#[Description('Remind workspace owners a few days before their free trial ends')]
class SendTrialReminders extends Command
{
    public function handle(): int
    {
        $days = (int) config('billing.trial_reminder_days');
        $sent = 0;

        Workspace::with('owner')
            ->whereNull('trial_reminder_sent_at')
            ->whereBetween('trial_ends_at', [now(), now()->addDays($days)])
            ->where(fn ($query) => $query->whereNull('subscription_status')->orWhere('subscription_status', '!=', 'active'))
            ->each(function (Workspace $workspace) use (&$sent): void {
                $remaining = max(1, (int) ceil(now()->diffInHours($workspace->trial_ends_at) / 24));
                $workspace->owner?->notify((new TrialEndingSoon($workspace, $remaining))->locale($workspace->owner->preferredLocale()));
                $workspace->forceFill(['trial_reminder_sent_at' => now()])->save();
                $sent++;
            });

        $this->info("Sent {$sent} trial reminders.");

        return self::SUCCESS;
    }
}
