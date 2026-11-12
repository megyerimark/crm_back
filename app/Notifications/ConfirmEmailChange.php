<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ConfirmEmailChange extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public string $url, public string $name) {}

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
            ->subject(__('account.email_change_subject'))
            ->greeting(__('account.greeting', ['name' => $this->name]))
            ->line(__('account.email_change_line'))
            ->action(__('account.email_change_action'), $this->url)
            ->line(__('account.verify_expires'));
    }
}
