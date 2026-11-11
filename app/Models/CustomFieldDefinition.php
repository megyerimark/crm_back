<?php

namespace App\Models;

use App\Models\Concerns\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['workspace_id', 'entity', 'key', 'label', 'type', 'options', 'position'])]
class CustomFieldDefinition extends Model
{
    use BelongsToWorkspace;

    public const TYPES = ['text', 'number', 'date', 'select', 'checkbox'];

    protected function casts(): array
    {
        return ['options' => 'array'];
    }
}
