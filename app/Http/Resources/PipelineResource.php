<?php

namespace App\Http\Resources;

use App\Models\Pipeline;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Pipeline
 */
class PipelineResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'is_default' => $this->is_default,
            'position' => $this->position,
            'stages' => PipelineStageResource::collection($this->whenLoaded('stages')),
            'deals_count' => $this->whenCounted('deals'),
        ];
    }
}
