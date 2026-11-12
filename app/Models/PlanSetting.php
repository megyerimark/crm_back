<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * Super Admin overrides of config/plans.php (limits, features, prices, Stripe price IDs).
 */
#[Fillable(['plan', 'overrides', 'updated_by'])]
class PlanSetting extends Model
{
    protected function casts(): array
    {
        return ['overrides' => 'array'];
    }
}
