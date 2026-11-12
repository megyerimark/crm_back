<?php

namespace App\Notifications;

use App\Models\Quote;
use App\Models\Workspace;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Number;

/**
 * The quote e-mail the customer receives, with the link to view and accept it online.
 */
class QuoteSent extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public Quote $quote, public Workspace $workspace, public ?string $personalMessage = null) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $company = $this->workspace->billing_name ?: $this->workspace->name;
        $mail = (new MailMessage)
            ->subject(__('quotes.mail.sent_subject', ['company' => $company, 'title' => $this->quote->title]))
            ->greeting($this->quote->recipient_name ? __('account.greeting', ['name' => $this->quote->recipient_name]) : __('quotes.mail.greeting'))
            ->line(__('quotes.mail.sent_line', ['company' => $company, 'number' => $this->quote->number]));

        if ($this->personalMessage) {
            $mail->line($this->personalMessage);
        }

        $mail->line(__('quotes.mail.sent_total', ['total' => Number::currency((float) $this->quote->gross_total, $this->quote->currency, app()->getLocale())]));

        if ($this->quote->valid_until) {
            $mail->line(__('quotes.mail.sent_valid', ['date' => $this->quote->valid_until->translatedFormat(__('quotes.date_format'))]));
        }

        if ($this->quote->owner?->email) {
            $mail->replyTo($this->quote->owner->email, $this->quote->owner->name);
        }

        return $mail
            ->action(__('quotes.mail.sent_action'), (string) $this->quote->publicUrl())
            ->salutation(__('quotes.mail.salutation', ['name' => $this->quote->owner?->name ?? $company, 'company' => $company]));
    }
}
