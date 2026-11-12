<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class VerifyEmailAddress extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public string $url) {}

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
            ->subject(__('account.verify_subject'))
            ->greeting(__('account.greeting', ['name' => $notifiable->name]))
            ->line(__('account.verify_line'))
            ->action(__('account.verify_action'), $this->url)
            ->line(__('account.verify_expires'));
    }
}
