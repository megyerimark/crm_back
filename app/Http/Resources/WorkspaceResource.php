<?php

namespace App\Http\Resources;

use App\Models\Workspace;
use App\Services\PlanService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Workspace
 */
class WorkspaceResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $plans = app(PlanService::class);

        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'country' => $this->country,
            'timezone' => $this->timezone,
            'currency' => $this->currency,
            'locale' => $this->locale,
            'owner_id' => $this->owner_id,
            'role' => $this->pivot?->role,
            'plan' => $this->plan,
            'effective_plan' => $plans->effectivePlan($this->resource)->value,
            'on_trial' => $this->onTrial(),
            'trial_ends_at' => $this->trial_ends_at,
            'subscription_status' => $this->subscription_status,
            'created_at' => $this->created_at,
        ];
    }
}
