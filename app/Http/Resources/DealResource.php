<?php

namespace App\Http\Resources;

use App\Models\Deal;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Deal
 */
class DealResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'pipeline_id' => $this->pipeline_id,
            'stage_id' => $this->stage_id,
            'stage' => PipelineStageResource::make($this->whenLoaded('stage')),
            'client_id' => $this->client_id,
            'client' => $this->whenLoaded('client', fn () => $this->client ? ['id' => $this->client->id, 'name' => $this->client->name] : null),
            'lead_id' => $this->lead_id,
            'lead' => $this->whenLoaded('lead', fn () => $this->lead ? ['id' => $this->lead->id, 'name' => $this->lead->name] : null),
            'value' => (float) $this->value,
            'currency' => $this->currency,
            'owner_id' => $this->owner_id,
            'owner' => UserSummaryResource::make($this->whenLoaded('owner')),
            'expected_close_date' => $this->expected_close_date?->toDateString(),
            'closed_at' => $this->closed_at,
            'position' => $this->position,
            'stage_changes' => $this->whenLoaded('stageChanges', fn () => $this->stageChanges->map(fn ($change) => [
                'from' => $change->fromStage?->name,
                'to' => $change->toStage?->name,
                'user' => $change->user?->name,
                'created_at' => $change->created_at,
            ])),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
