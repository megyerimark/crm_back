<?php

namespace App\Http\Resources;

use App\Models\AutomationRule;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin AutomationRule
 */
class AutomationRuleResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'trigger' => $this->trigger,
            'conditions' => $this->conditions ?? (object) [],
            'action' => $this->action,
            'action_config' => $this->action_config ?? (object) [],
            'is_active' => $this->is_active,
            'runs_count' => $this->runs_count,
            'created_at' => $this->created_at,
        ];
    }
}
