<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ResetPasswordLink extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public string $url, public int $minutes) {}

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
            ->subject(__('account.reset_subject'))
            ->greeting(__('account.greeting', ['name' => $notifiable->name]))
            ->line(__('account.reset_line'))
            ->action(__('account.reset_action'), $this->url)
            ->line(__('account.reset_expires', ['minutes' => $this->minutes]));
    }
}
