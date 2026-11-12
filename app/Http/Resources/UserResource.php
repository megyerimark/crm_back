<?php

namespace App\Http\Resources;

use App\Models\User;
use App\Services\Auth\TwoFactorService;
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
            'email_verified_at' => $this->email_verified_at,
            'pending_email' => $this->pending_email,
            'two_factor_enabled' => $this->hasTwoFactorEnabled(),
            'two_factor_recovery_codes_left' => $this->hasTwoFactorEnabled() ? app(TwoFactorService::class)->remainingRecoveryCodes($this->resource) : 0,
            'notify_task_reminders' => $this->notify_task_reminders,
            'notify_daily_digest' => $this->notify_daily_digest,
            'notify_new_leads' => $this->notify_new_leads,
            'current_workspace_id' => $this->current_workspace_id,
            'created_at' => $this->created_at,
        ];
    }
}
