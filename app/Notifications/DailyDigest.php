<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Morning summary of today's and overdue tasks so nothing is forgotten.
 */
class DailyDigest extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * @param  list<array{workspace: string, tasks: list<array{title: string, related: ?string, due: string, overdue: bool}>}>  $groups
     */
    public function __construct(public array $groups) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $count = array_sum(array_map(fn ($group) => count($group['tasks']), $this->groups));

        return (new MailMessage)
            ->subject(__('account.digest_subject', ['count' => $count]))
            ->markdown('mail.daily-digest', [
                'name' => $notifiable->name,
                'groups' => $this->groups,
                'url' => rtrim(config('app.frontend_url'), '/').'/tasks',
            ]);
    }
}
