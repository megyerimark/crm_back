<?php

namespace App\Notifications;

use App\Models\SupportTicket;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Sent to platform staff (super admins) when a customer opens a ticket.
 */
class SupportTicketCreated extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public SupportTicket $ticket, public string $excerpt) {}

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
            ->subject('['.strtoupper($this->ticket->priority).'] #'.$this->ticket->id.' '.$this->ticket->subject)
            ->greeting(__('support.staff_greeting'))
            ->line(__('support.staff_new', ['workspace' => $this->ticket->workspace?->name, 'plan' => ucfirst((string) $this->ticket->plan), 'priority' => $this->ticket->priority]))
            ->line('„'.$this->excerpt.'”')
            ->action(__('support.open_ticket'), rtrim(config('app.frontend_url'), '/').'/admin?tab=support&ticket='.$this->ticket->id);
    }
}
