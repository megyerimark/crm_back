<?php

namespace App\Models;

use App\Models\Concerns\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['workspace_id', 'name', 'trigger', 'conditions', 'action', 'action_config', 'is_active'])]
class AutomationRule extends Model
{
    use BelongsToWorkspace;

    public const TRIGGERS = ['lead_created', 'deal_won', 'deal_lost', 'client_created'];

    public const ACTIONS = ['create_task', 'assign_owner'];

    /**
     * @var array<string, mixed>
     */
    protected $attributes = ['is_active' => true, 'runs_count' => 0];

    protected function casts(): array
    {
        return [
            'conditions' => 'array',
            'action_config' => 'array',
            'is_active' => 'boolean',
        ];
    }
}
