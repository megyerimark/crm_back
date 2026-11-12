<?php

namespace App\Models\Concerns;

use App\Models\Workspace;
use App\Support\CurrentWorkspace;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Scopes a model to the current workspace (multi-tenancy) and fills workspace_id on create.
 */
trait BelongsToWorkspace
{
    public static function bootBelongsToWorkspace(): void
    {
        static::addGlobalScope('workspace', function (Builder $builder): void {
            $current = app(CurrentWorkspace::class);

            if ($current->has()) {
                $builder->where($builder->qualifyColumn('workspace_id'), $current->id());
            }
        });

        static::creating(function ($model): void {
            $current = app(CurrentWorkspace::class);

            if (! $model->workspace_id && $current->has()) {
                $model->workspace_id = $current->id();
            }
        });
    }

    /**
     * @return BelongsTo<Workspace, $this>
     */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }
}
