<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Customer support request. Priority follows the plan of the workspace (Business > Pro > Free).
 */
#[Fillable(['workspace_id', 'user_id', 'subject', 'category', 'status', 'priority', 'plan', 'assigned_to'])]
class SupportTicket extends Model
{
    public const CATEGORIES = ['question', 'bug', 'billing', 'feature', 'account'];

    public const STATUSES = ['open', 'answered', 'pending', 'closed'];

    public const PRIORITIES = ['low', 'normal', 'high', 'urgent'];

    /** Plan → default priority (paid plans get faster support). */
    public const PLAN_PRIORITY = ['free' => 'low', 'pro' => 'normal', 'business' => 'high'];

    /**
     * @var array<string, mixed>
     */
    protected $attributes = ['status' => 'open', 'priority' => 'normal', 'category' => 'question'];

    protected function casts(): array
    {
        return [
            'last_customer_reply_at' => 'datetime',
            'last_staff_reply_at' => 'datetime',
            'closed_at' => 'datetime',
        ];
    }

    /**
     * @return HasMany<SupportTicketMessage, $this>
     */
    public function messages(): HasMany
    {
        return $this->hasMany(SupportTicketMessage::class)->orderBy('id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<Workspace, $this>
     */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }
}
