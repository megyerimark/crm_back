<?php

namespace App\Models;

use App\Models\Concerns\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['workspace_id', 'name', 'is_default', 'position'])]
class Pipeline extends Model
{
    use BelongsToWorkspace;

    /** Default stages from the product spec (3.4). */
    public const DEFAULT_STAGES = [
        ['key' => 'new', 'type' => 'open'],
        ['key' => 'contacted', 'type' => 'open'],
        ['key' => 'proposal_sent', 'type' => 'open'],
        ['key' => 'negotiation', 'type' => 'open'],
        ['key' => 'won', 'type' => 'won'],
        ['key' => 'lost', 'type' => 'lost'],
    ];

    protected function casts(): array
    {
        return ['is_default' => 'boolean'];
    }

    /**
     * @return HasMany<PipelineStage, $this>
     */
    public function stages(): HasMany
    {
        return $this->hasMany(PipelineStage::class)->orderBy('position');
    }

    /**
     * @return HasMany<Deal, $this>
     */
    public function deals(): HasMany
    {
        return $this->hasMany(Deal::class);
    }

    public function createDefaultStages(string $locale = 'hu'): void
    {
        foreach (self::DEFAULT_STAGES as $position => $stage) {
            $this->stages()->create([
                'workspace_id' => $this->workspace_id,
                'name' => trans('crm.stages.'.$stage['key'], [], $locale),
                'type' => $stage['type'],
                'position' => $position,
            ]);
        }
    }
}
