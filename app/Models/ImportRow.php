<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['import_id', 'row_number', 'status', 'messages', 'record_id'])]
class ImportRow extends Model
{
    protected function casts(): array
    {
        return ['messages' => 'array'];
    }
}
