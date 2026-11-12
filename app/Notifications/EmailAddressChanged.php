<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class EmailAddressChanged extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public string $name, public string $newEmail) {}

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
            ->subject(__('account.email_changed_subject'))
            ->greeting(__('account.greeting', ['name' => $this->name]))
            ->line(__('account.email_changed_line', ['email' => $this->newEmail]))
            ->line(__('account.security_hint'));
    }
}
