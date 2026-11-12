<?php

namespace App\Http\Resources;

use App\Models\Activity;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Activity
 */
class ActivityResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type,
            'title' => $this->title,
            'body' => $this->body,
            'meta' => $this->meta,
            'subject_type' => $this->subject_type,
            'subject_id' => $this->subject_id,
            'subject' => $this->whenLoaded('subject', fn () => $this->subject ? ['id' => $this->subject->getKey(), 'name' => $this->subject->getAttribute('name') ?? $this->subject->getAttribute('title')] : null),
            'user' => UserSummaryResource::make($this->whenLoaded('user')),
            'occurred_at' => $this->occurred_at,
        ];
    }
}
