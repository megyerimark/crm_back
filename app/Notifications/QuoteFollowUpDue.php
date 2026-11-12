<?php

namespace App\Notifications;

use App\Models\Quote;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Reminds the owner to follow up a quote the customer has not answered yet.
 */
class QuoteFollowUpDue extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public Quote $quote) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return $notifiable->notify_quote_updates ? ['mail', 'database'] : ['database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $replace = [
            'number' => $this->quote->number,
            'title' => $this->quote->title,
            'customer' => $this->quote->recipient_name ?: (string) $this->quote->recipient_email,
            'days' => (int) $this->quote->sent_at?->diffInDays(now()),
        ];

        return (new MailMessage)
            ->subject(__('quotes.mail.follow_up_subject', $replace))
            ->greeting(__('account.greeting', ['name' => $notifiable->name]))
            ->line(__('quotes.mail.follow_up_line', $replace))
            ->line($this->quote->first_viewed_at ? __('quotes.mail.follow_up_viewed', ['count' => $this->quote->view_count]) : __('quotes.mail.follow_up_not_viewed'))
            ->line(__('quotes.mail.follow_up_tip'))
            ->action(__('quotes.mail.open_quote'), rtrim(config('app.frontend_url'), '/').'/quotes/'.$this->quote->id)
            ->line(__('account.unsubscribe_hint'));
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'quote_follow_up',
            'workspace_id' => $this->quote->workspace_id,
            'quote_id' => $this->quote->id,
            'number' => $this->quote->number,
            'title' => $this->quote->title,
            'name' => $this->quote->recipient_name ?: $this->quote->recipient_email,
        ];
    }
}
