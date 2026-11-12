<?php

namespace App\Services;

use App\Models\Activity;
use Illuminate\Database\Eloquent\Model;

class ActivityLogger
{
    /**
     * @param  array<string, mixed>  $meta
     */
    public function log(Model $subject, string $type, ?string $title = null, ?string $body = null, array $meta = []): Activity
    {
        return Activity::create([
            'workspace_id' => $subject->getAttribute('workspace_id'),
            'user_id' => auth()->id(),
            'subject_type' => $subject->getMorphClass(),
            'subject_id' => $subject->getKey(),
            'type' => $type,
            'title' => $title,
            'body' => $body,
            'meta' => $meta ?: null,
            'occurred_at' => now(),
        ]);
    }
}
