<?php

namespace App\Notifications;

use App\Models\Form;
use App\Models\Lead;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class NewLeadReceived extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public Lead $lead, public Form $form) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return $notifiable instanceof AnonymousNotifiable ? ['mail'] : ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject(__('crm.mail.new_lead_subject', ['name' => $this->lead->name]))
            ->line(__('crm.mail.new_lead_line', ['form' => $this->form->name]))
            ->line(collect([$this->lead->name, $this->lead->email, $this->lead->phone])->filter()->implode(' · '))
            ->action(__('crm.mail.open_lead'), rtrim(config('app.frontend_url'), '/').'/leads/'.$this->lead->id);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'new_lead',
            'workspace_id' => $this->lead->workspace_id,
            'lead_id' => $this->lead->id,
            'name' => $this->lead->name,
            'form' => $this->form->name,
        ];
    }
}
