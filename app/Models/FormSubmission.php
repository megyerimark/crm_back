<?php

namespace App\Models;

use App\Models\Concerns\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['workspace_id', 'form_id', 'lead_id', 'data', 'status', 'utm_source', 'utm_medium', 'utm_campaign', 'utm_content', 'referrer', 'ip_hash', 'user_agent', 'marketing_consent', 'privacy_accepted', 'privacy_version'])]
class FormSubmission extends Model
{
    use BelongsToWorkspace;

    protected function casts(): array
    {
        return [
            'data' => 'array',
            'marketing_consent' => 'boolean',
            'privacy_accepted' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<Form, $this>
     */
    public function form(): BelongsTo
    {
        return $this->belongsTo(Form::class)->withTrashed();
    }

    /**
     * @return BelongsTo<Lead, $this>
     */
    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }
}
