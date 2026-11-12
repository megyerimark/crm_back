<?php

namespace App\Notifications;

use App\Models\Quote;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Tells the quote owner that the customer opened, accepted or declined the quote.
 */
class QuoteStatusChanged extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * @param  'viewed'|'accepted'|'declined'  $event
     */
    public function __construct(public Quote $quote, public string $event) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return $notifiable->notify_quote_updates ? ['mail', 'database'] : ['database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $replace = ['number' => $this->quote->number, 'title' => $this->quote->title, 'customer' => $this->customer()];

        $mail = (new MailMessage)
            ->subject(__("quotes.mail.{$this->event}_subject", $replace))
            ->greeting(__('account.greeting', ['name' => $notifiable->name]))
            ->line(__("quotes.mail.{$this->event}_line", $replace));

        if ($this->event === 'declined' && $this->quote->decline_reason) {
            $mail->line(__('quotes.mail.declined_reason', ['reason' => $this->quote->decline_reason]));
        }

        return $mail
            ->action(__('quotes.mail.open_quote'), rtrim(config('app.frontend_url'), '/').'/quotes/'.$this->quote->id)
            ->line(__('account.unsubscribe_hint'));
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'quote_'.$this->event,
            'workspace_id' => $this->quote->workspace_id,
            'quote_id' => $this->quote->id,
            'number' => $this->quote->number,
            'title' => $this->quote->title,
            'name' => $this->customer(),
        ];
    }

    protected function customer(): string
    {
        return $this->quote->accepted_by_name ?: ($this->quote->recipient_name ?: (string) $this->quote->recipient_email);
    }
}
