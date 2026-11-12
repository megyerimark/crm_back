<?php

namespace App\Notifications;

use App\Models\Invitation;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class WorkspaceInvitation extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public Invitation $invitation, public string $plainToken) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $workspace = $this->invitation->workspace;
        $url = rtrim(config('app.frontend_url'), '/').'/invite/'.$this->plainToken;

        return (new MailMessage)
            ->subject(__('crm.mail.invitation_subject', ['workspace' => $workspace->name]))
            ->line(__('crm.mail.invitation_line', ['inviter' => $this->invitation->inviter?->name, 'workspace' => $workspace->name]))
            ->action(__('crm.mail.invitation_action'), $url)
            ->line(__('crm.mail.invitation_expires', ['date' => $this->invitation->expires_at->timezone($workspace->timezone)->format('Y-m-d H:i')]));
    }
}
