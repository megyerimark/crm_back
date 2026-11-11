<?php

namespace App\Http\Resources;

use App\Models\CustomFieldDefinition;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin CustomFieldDefinition
 */
class CustomFieldResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'entity' => $this->entity,
            'key' => $this->key,
            'label' => $this->label,
            'type' => $this->type,
            'options' => $this->options ?? [],
            'position' => $this->position,
        ];
    }
}
