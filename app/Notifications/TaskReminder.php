<?php

namespace App\Notifications;

use App\Models\Task;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class TaskReminder extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public Task $task, public string $timezone) {}

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
            ->subject(__('crm.mail.reminder_subject', ['title' => $this->task->title]))
            ->line(__('crm.mail.reminder_line', ['title' => $this->task->title, 'due' => $this->task->due_at?->timezone($this->timezone)->format('Y-m-d H:i')]))
            ->action(__('crm.mail.open_task'), rtrim(config('app.frontend_url'), '/').'/tasks');
    }
}
