<?php

namespace App\Http\Resources;

use App\Models\Form;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Form
 */
class PublicFormResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'slug' => $this->slug,
            'locale' => $this->locale,
            'title' => $this->title ?: $this->name,
            'description' => $this->description,
            'fields' => $this->fields,
            'privacy_url' => $this->privacy_url,
            'ask_marketing_consent' => $this->ask_marketing_consent,
            'show_branding' => $this->show_branding,
            'workspace_name' => $this->workspace?->name,
        ];
    }
}
