<?php

namespace App\Notifications;

use App\Models\SupportTicket;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Sent to the customer when support answers their ticket.
 */
class SupportTicketReplied extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public SupportTicket $ticket, public string $reply) {}

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
            ->subject(__('support.reply_subject', ['id' => $this->ticket->id, 'subject' => $this->ticket->subject]))
            ->greeting(__('account.greeting', ['name' => $notifiable->name]))
            ->line(__('support.reply_line'))
            ->line($this->reply)
            ->action(__('support.open_ticket'), rtrim(config('app.frontend_url'), '/').'/support?ticket='.$this->ticket->id);
    }
}
