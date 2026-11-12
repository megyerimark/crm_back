<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class AccountDeleted extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public string $name) {}

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
            ->subject(__('account.deleted_subject'))
            ->greeting(__('account.greeting', ['name' => $this->name]))
            ->line(__('account.deleted_line'))
            ->line(__('account.deleted_bye'));
    }
}
