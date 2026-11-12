<?php

namespace App\Http\Resources;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin User
 */
class UserResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'locale' => $this->locale,
            'theme' => $this->theme,
            'timezone' => $this->timezone,
            'is_super_admin' => $this->is_super_admin,
            'current_workspace_id' => $this->current_workspace_id,
            'created_at' => $this->created_at,
        ];
    }
}
