<?php

namespace App\Http\Resources;

use App\Models\FormSubmission;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin FormSubmission
 */
class FormSubmissionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'form_id' => $this->form_id,
            'form' => $this->whenLoaded('form', fn () => ['id' => $this->form?->id, 'name' => $this->form?->name]),
            'lead_id' => $this->lead_id,
            'data' => $this->data,
            'status' => $this->status,
            'utm' => [
                'source' => $this->utm_source,
                'medium' => $this->utm_medium,
                'campaign' => $this->utm_campaign,
                'content' => $this->utm_content,
            ],
            'referrer' => $this->referrer,
            'marketing_consent' => $this->marketing_consent,
            'privacy_accepted' => $this->privacy_accepted,
            'privacy_version' => $this->privacy_version,
            'created_at' => $this->created_at,
        ];
    }
}
