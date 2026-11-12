<?php

namespace App\Models;

use App\Models\Concerns\BelongsToWorkspace;
use Database\Factories\QuoteFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable(['workspace_id', 'number', 'title', 'client_id', 'lead_id', 'deal_id', 'owner_id', 'recipient_name', 'recipient_email', 'currency', 'valid_until', 'introduction', 'terms', 'follow_up_at', 'source', 'external_id'])]
#[Hidden(['public_token'])]
class Quote extends Model
{
    /** @use HasFactory<QuoteFactory> */
    use BelongsToWorkspace, HasFactory, SoftDeletes;

    public const STATUSES = ['draft', 'sent', 'viewed', 'accepted', 'declined', 'expired'];

    /** Statuses in which the customer has the quote and we are waiting for an answer. */
    public const AWAITING = ['sent', 'viewed'];

    /** Maximum number of automatic follow-up reminders per quote. */
    public const MAX_FOLLOW_UPS = 3;

    /**
     * @var array<string, mixed>
     */
    protected $attributes = ['status' => 'draft', 'source' => 'manual', 'net_total' => 0, 'vat_total' => 0, 'gross_total' => 0, 'view_count' => 0, 'follow_up_count' => 0];

    protected function casts(): array
    {
        return [
            'net_total' => 'decimal:2',
            'vat_total' => 'decimal:2',
            'gross_total' => 'decimal:2',
            'valid_until' => 'date',
            'sent_at' => 'datetime',
            'first_viewed_at' => 'datetime',
            'last_viewed_at' => 'datetime',
            'accepted_at' => 'datetime',
            'declined_at' => 'datetime',
            'expired_at' => 'datetime',
            'follow_up_at' => 'datetime',
        ];
    }

    public function isDraft(): bool
    {
        return $this->status === 'draft';
    }

    public function isAwaitingAnswer(): bool
    {
        return in_array($this->status, self::AWAITING, true);
    }

    public function isPastValidity(): bool
    {
        return $this->valid_until !== null && $this->valid_until->endOfDay()->isPast();
    }

    public function publicUrl(): ?string
    {
        return $this->public_token ? rtrim(config('app.frontend_url'), '/').'/q/'.$this->public_token : null;
    }

    /**
     * @return HasMany<QuoteItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(QuoteItem::class)->orderBy('position')->orderBy('id');
    }

    /**
     * @return HasMany<QuoteEvent, $this>
     */
    public function events(): HasMany
    {
        return $this->hasMany(QuoteEvent::class)->latest('created_at')->latest('id');
    }

    /**
     * @return BelongsTo<Client, $this>
     */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    /**
     * @return BelongsTo<Lead, $this>
     */
    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }

    /**
     * @return BelongsTo<Deal, $this>
     */
    public function deal(): BelongsTo
    {
        return $this->belongsTo(Deal::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }
}
