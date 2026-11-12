<?php

namespace App\Http\Resources;

use App\Models\Task;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Task
 */
class TaskResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'description' => $this->description,
            'due_at' => $this->due_at,
            'priority' => $this->priority,
            'status' => $this->status,
            'is_overdue' => $this->status !== 'done' && $this->due_at?->isPast(),
            'assignee_id' => $this->assignee_id,
            'assignee' => UserSummaryResource::make($this->whenLoaded('assignee')),
            'client_id' => $this->client_id,
            'client' => $this->whenLoaded('client', fn () => $this->client ? ['id' => $this->client->id, 'name' => $this->client->name] : null),
            'lead_id' => $this->lead_id,
            'lead' => $this->whenLoaded('lead', fn () => $this->lead ? ['id' => $this->lead->id, 'name' => $this->lead->name] : null),
            'deal_id' => $this->deal_id,
            'deal' => $this->whenLoaded('deal', fn () => $this->deal ? ['id' => $this->deal->id, 'title' => $this->deal->title] : null),
            'completed_at' => $this->completed_at,
            'created_at' => $this->created_at,
        ];
    }
}
