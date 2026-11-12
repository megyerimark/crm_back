<?php

namespace App\Services\Quotes;

use App\Models\Deal;
use App\Models\DealStageChange;
use App\Models\PipelineStage;
use App\Models\Quote;
use App\Models\QuoteEvent;
use App\Models\Task;
use App\Models\User;
use App\Models\Workspace;
use App\Notifications\QuoteFollowUpDue;
use App\Notifications\QuoteSent;
use App\Notifications\QuoteStatusChanged;
use App\Services\ActivityLogger;
use App\Services\AutomationService;
use App\Services\PlanService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Quote lifecycle: draft → sent → viewed → accepted | declined | expired.
 *
 * Status changes coming from the customer only happen through the unguessable public token,
 * staff can record offline answers (phone, e-mail) explicitly.
 */
class QuoteService
{
    public function __construct(
        protected PlanService $plans,
        protected ActivityLogger $activities,
        protected AutomationService $automations,
    ) {}

    /**
     * Next per-workspace number, e.g. AJ-2026-0007. Must run inside a transaction.
     */
    public function nextNumber(Workspace $workspace): string
    {
        Workspace::whereKey($workspace->id)->lockForUpdate()->first();

        $prefix = 'AJ-'.now()->year.'-';
        $last = Quote::withoutGlobalScopes()->withTrashed()
            ->where('workspace_id', $workspace->id)
            ->where('number', 'like', $prefix.'%')
            ->pluck('number')
            ->map(fn (string $number) => (int) Str::after($number, $prefix))
            ->max() ?? 0;

        return $prefix.str_pad((string) ($last + 1), 4, '0', STR_PAD_LEFT);
    }

    /**
     * Replaces the line items and recalculates the totals.
     *
     * @param  list<array{description: string, quantity?: float|int|string|null, unit?: string|null, unit_price?: float|int|string|null, vat_rate?: float|int|string|null}>  $items
     */
    public function syncItems(Quote $quote, array $items): void
    {
        $quote->items()->delete();

        foreach (array_values($items) as $position => $item) {
            $quote->items()->create([
                'description' => $item['description'],
                'quantity' => $item['quantity'] ?? 1,
                'unit' => $item['unit'] ?? null,
                'unit_price' => $item['unit_price'] ?? 0,
                'vat_rate' => $item['vat_rate'] ?? 27,
                'position' => $position,
            ]);
        }

        $this->recalculate($quote);
    }

    public function recalculate(Quote $quote): void
    {
        $items = $quote->items()->get();
        $net = round($items->sum(fn ($item) => $item->netAmount()), 2);
        $vat = round($items->sum(fn ($item) => $item->vatAmount()), 2);

        $quote->forceFill(['net_total' => $net, 'vat_total' => $vat, 'gross_total' => round($net + $vat, 2)])->save();
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    public function record(Quote $quote, string $type, array $meta = [], ?User $user = null): QuoteEvent
    {
        return $quote->events()->create(['type' => $type, 'meta' => $meta ?: null, 'user_id' => $user?->id, 'created_at' => now()]);
    }

    /**
     * Sends (or re-sends) the quote to the customer by e-mail with the public link.
     */
    public function send(Quote $quote, User $sender, string $email, ?string $name = null, ?string $message = null): Quote
    {
        if (! $quote->isDraft() && ! $quote->isAwaitingAnswer() && $quote->status !== 'expired') {
            throw ValidationException::withMessages(['status' => __('quotes.errors.not_sendable')]);
        }

        if ($quote->items()->count() === 0) {
            throw ValidationException::withMessages(['items' => __('quotes.errors.no_items')]);
        }

        if ($quote->isPastValidity()) {
            throw ValidationException::withMessages(['valid_until' => __('quotes.errors.validity_passed')]);
        }

        $workspace = $quote->workspace()->firstOrFail();
        $resend = $quote->isAwaitingAnswer();

        $quote->forceFill([
            'status' => $quote->status === 'viewed' ? 'viewed' : 'sent',
            'recipient_email' => $email,
            'recipient_name' => $name ?? $quote->recipient_name,
            'public_token' => $quote->public_token ?? Str::random(48),
            'sent_at' => now(),
            'expired_at' => null,
            'follow_up_at' => $this->plans->hasFeature($workspace, 'quote_follow_ups') ? now()->addDays($workspace->quote_follow_up_days ?: 3) : null,
            'follow_up_count' => 0,
        ])->save();

        Notification::route('mail', [$email => $quote->recipient_name])
            ->notify((new QuoteSent($quote, $workspace, $message))->locale($workspace->locale));

        $this->record($quote, $resend ? 'resent' : 'sent', ['email' => $email], $sender);
        $this->logOnCustomer($quote, 'quote_sent', __('quotes.activity.sent', ['number' => $quote->number, 'title' => $quote->title]));

        return $quote;
    }

    /**
     * Called when the customer opens the public link.
     */
    public function registerView(Quote $quote): void
    {
        if (! $quote->isAwaitingAnswer()) {
            return;
        }

        $firstView = $quote->status === 'sent';

        $quote->forceFill([
            'status' => 'viewed',
            'first_viewed_at' => $quote->first_viewed_at ?? now(),
            'last_viewed_at' => now(),
            'view_count' => $quote->view_count + 1,
        ])->save();

        if ($firstView) {
            $this->record($quote, 'viewed');
            $this->notifyOwner($quote, 'viewed');
        }
    }

    public function accept(Quote $quote, string $acceptedBy, ?User $staff = null): Quote
    {
        $this->ensureAnswerable($quote, $staff !== null);

        DB::transaction(function () use ($quote, $acceptedBy, $staff): void {
            $quote->forceFill(['status' => 'accepted', 'accepted_at' => now(), 'accepted_by_name' => $acceptedBy, 'follow_up_at' => null])->save();
            $this->record($quote, $staff ? 'marked_accepted' : 'accepted', ['name' => $acceptedBy], $staff);
            $this->logOnCustomer($quote, 'quote_accepted', __('quotes.activity.accepted', ['number' => $quote->number, 'name' => $acceptedBy]));
            $this->winDeal($quote, $staff);
        });

        if (! $staff) {
            $this->notifyOwner($quote, 'accepted');
        }

        return $quote;
    }

    public function decline(Quote $quote, ?string $reason, ?User $staff = null): Quote
    {
        $this->ensureAnswerable($quote, $staff !== null);

        $quote->forceFill(['status' => 'declined', 'declined_at' => now(), 'decline_reason' => $reason, 'follow_up_at' => null])->save();
        $this->record($quote, $staff ? 'marked_declined' : 'declined', array_filter(['reason' => $reason]), $staff);
        $this->logOnCustomer($quote, 'quote_declined', __('quotes.activity.declined', ['number' => $quote->number]), $reason);

        if (! $staff) {
            $this->notifyOwner($quote, 'declined');
        }

        return $quote;
    }

    /**
     * Marks awaiting quotes whose validity has passed as expired.
     */
    public function expireOverdue(): int
    {
        $expired = 0;

        Quote::withoutGlobalScopes()
            ->whereIn('status', Quote::AWAITING)
            ->whereNotNull('valid_until')
            ->whereDate('valid_until', '<', now()->toDateString())
            ->chunkById(200, function ($quotes) use (&$expired): void {
                foreach ($quotes as $quote) {
                    $quote->forceFill(['status' => 'expired', 'expired_at' => now(), 'follow_up_at' => null])->save();
                    $this->record($quote, 'expired');
                    $expired++;
                }
            });

        return $expired;
    }

    /**
     * Creates a follow-up task for the owner and e-mails them, then schedules the next reminder.
     */
    public function followUp(Quote $quote, Workspace $workspace): void
    {
        $owner = $quote->owner;
        $count = $quote->follow_up_count + 1;

        $quote->forceFill([
            'follow_up_count' => $count,
            'follow_up_at' => $count < Quote::MAX_FOLLOW_UPS ? now()->addDays($workspace->quote_follow_up_days ?: 3) : null,
        ])->save();

        if (! $owner) {
            return;
        }

        Task::withoutGlobalScopes()->create([
            'workspace_id' => $quote->workspace_id,
            'title' => __('quotes.follow_up.task_title', ['number' => $quote->number, 'title' => $quote->title], $workspace->locale),
            'description' => $quote->recipient_name ? $quote->recipient_name.' · '.$quote->recipient_email : $quote->recipient_email,
            'due_at' => now()->addHours(2),
            'priority' => 'high',
            'assignee_id' => $owner->id,
            'client_id' => $quote->client_id,
            'lead_id' => $quote->lead_id,
            'deal_id' => $quote->deal_id,
        ]);

        $this->record($quote, 'follow_up', ['count' => $count]);
        $owner->notify((new QuoteFollowUpDue($quote))->locale($owner->preferredLocale()));
    }

    protected function ensureAnswerable(Quote $quote, bool $byStaff): void
    {
        $allowed = $byStaff ? [...Quote::AWAITING, 'draft', 'expired'] : Quote::AWAITING;

        if (! in_array($quote->status, $allowed, true)) {
            throw ValidationException::withMessages(['status' => __('quotes.errors.already_answered')]);
        }

        if (! $byStaff && $quote->isPastValidity()) {
            throw ValidationException::withMessages(['status' => __('quotes.errors.validity_passed')]);
        }
    }

    protected function notifyOwner(Quote $quote, string $event): void
    {
        $owner = $quote->owner;

        if ($owner) {
            $owner->notify((new QuoteStatusChanged($quote, $event))->locale($owner->preferredLocale()));
        }
    }

    protected function logOnCustomer(Quote $quote, string $type, string $title, ?string $body = null): void
    {
        foreach ([$quote->client()->withoutGlobalScopes()->first(), $quote->lead()->withoutGlobalScopes()->first()] as $subject) {
            if ($subject && $subject->workspace_id === $quote->workspace_id) {
                $this->activities->log($subject, $type, $title, $body, ['quote_id' => $quote->id]);
            }
        }
    }

    /**
     * An accepted quote closes its linked deal as won (if the pipeline has a won stage).
     */
    protected function winDeal(Quote $quote, ?User $staff): void
    {
        $deal = $quote->deal_id ? Deal::withoutGlobalScopes()->where('workspace_id', $quote->workspace_id)->find($quote->deal_id) : null;

        if (! $deal) {
            return;
        }

        $won = PipelineStage::withoutGlobalScopes()->where('pipeline_id', $deal->pipeline_id)->where('type', 'won')->orderBy('position')->first();

        if (! $won || $deal->stage_id === $won->id) {
            return;
        }

        $from = $deal->stage_id;
        $deal->forceFill(['stage_id' => $won->id, 'closed_at' => now(), 'value' => $deal->value > 0 ? $deal->value : $quote->net_total])->save();
        DealStageChange::create(['workspace_id' => $deal->workspace_id, 'deal_id' => $deal->id, 'from_stage_id' => $from, 'to_stage_id' => $won->id, 'user_id' => $staff?->id]);

        $workspace = Workspace::find($quote->workspace_id);

        if ($workspace) {
            $this->automations->fire($workspace, 'deal_won', $deal);
        }
    }
}
