<?php

namespace App\Http\Resources;

use App\Models\Client;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Client
 */
class ClientResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'company' => $this->company,
            'email' => $this->email,
            'phone' => $this->phone,
            'address' => $this->address,
            'type' => $this->type,
            'status' => $this->status,
            'category' => $this->category,
            'tags' => $this->tags ?? [],
            'custom_fields' => $this->custom_fields ?? (object) [],
            'notes' => $this->notes,
            'source' => $this->source,
            'owner' => UserSummaryResource::make($this->whenLoaded('owner')),
            'owner_id' => $this->owner_id,
            'lead_id' => $this->lead_id,
            'open_tasks_count' => $this->whenCounted('openTasks'),
            'deals_count' => $this->whenCounted('deals'),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
