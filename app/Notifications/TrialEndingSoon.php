<?php

namespace App\Notifications;

use App\Models\Workspace;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class TrialEndingSoon extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public Workspace $workspace, public int $days) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject(__('billing.trial_ending_subject', ['days' => $this->days]))
            ->line(__('billing.trial_ending_line', [
                'workspace' => $this->workspace->name,
                'date' => $this->workspace->trial_ends_at?->timezone($this->workspace->timezone)->format('Y-m-d'),
            ]))
            ->action(__('billing.trial_ending_action'), rtrim(config('app.frontend_url'), '/').'/settings/subscription');
    }
}
