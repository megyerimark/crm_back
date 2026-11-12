<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class SecurityNotice extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public string $event) {}

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
            ->subject(__('account.'.$this->event.'_subject'))
            ->greeting(__('account.greeting', ['name' => $notifiable->name]))
            ->line(__('account.'.$this->event.'_line'))
            ->line(__('account.security_hint'));
    }
}
