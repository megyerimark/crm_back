<?php

namespace App\Http\Resources;

use App\Models\Lead;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Lead
 */
class LeadResource extends JsonResource
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
            'message' => $this->message,
            'status' => $this->status,
            'tags' => $this->tags ?? [],
            'source' => $this->source,
            'form' => $this->whenLoaded('form', fn () => $this->form ? ['id' => $this->form->id, 'name' => $this->form->name] : null),
            'form_id' => $this->form_id,
            'utm' => [
                'source' => $this->utm_source,
                'medium' => $this->utm_medium,
                'campaign' => $this->utm_campaign,
                'content' => $this->utm_content,
            ],
            'referrer' => $this->referrer,
            'owner' => UserSummaryResource::make($this->whenLoaded('owner')),
            'owner_id' => $this->owner_id,
            'client_id' => $this->client_id,
            'converted_at' => $this->converted_at,
            'submissions' => FormSubmissionResource::collection($this->whenLoaded('submissions')),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
