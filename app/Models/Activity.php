<?php

namespace App\Models;

use App\Models\Concerns\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

#[Fillable(['workspace_id', 'user_id', 'subject_type', 'subject_id', 'type', 'title', 'body', 'meta', 'occurred_at'])]
class Activity extends Model
{
    use BelongsToWorkspace;

    /** Types a user may log manually; the rest are written by the system. */
    public const MANUAL_TYPES = ['call', 'email', 'meeting', 'online_meeting', 'note'];

    public const SYSTEM_TYPES = ['status_change', 'system', 'form_submission', 'conversion', 'stage_change'];

    protected function casts(): array
    {
        return [
            'meta' => 'array',
            'occurred_at' => 'datetime',
        ];
    }

    public function subject(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
