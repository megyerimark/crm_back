<?php

namespace App\Http\Resources;

use App\Models\Form;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Form
 */
class FormResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'status' => $this->status,
            'locale' => $this->locale,
            'title' => $this->title,
            'description' => $this->description,
            'fields' => $this->fields,
            'thank_you_message' => $this->thank_you_message,
            'privacy_url' => $this->privacy_url,
            'privacy_version' => $this->privacy_version,
            'ask_marketing_consent' => $this->ask_marketing_consent,
            'notify_owner' => $this->notify_owner,
            'notify_emails' => $this->notify_emails ?? [],
            'show_branding' => $this->show_branding,
            'default_owner_id' => $this->default_owner_id,
            'submissions_count' => $this->submissions_count,
            'public_url' => rtrim(config('app.frontend_url'), '/').'/f/'.$this->slug,
            'embed_code' => '<iframe src="'.rtrim(config('app.frontend_url'), '/').'/f/'.$this->slug.'?embed=1" style="width:100%;min-height:640px;border:0" loading="lazy"></iframe>',
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
