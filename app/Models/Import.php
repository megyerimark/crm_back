<?php

namespace App\Models;

use App\Models\Concerns\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['workspace_id', 'user_id', 'target', 'original_name', 'disk', 'path', 'status', 'sheet', 'sheets', 'headers', 'mapping', 'duplicate_mode', 'total_rows'])]
#[Hidden(['path', 'disk'])]
class Import extends Model
{
    use BelongsToWorkspace;

    public const TARGETS = ['clients', 'leads'];

    /**
     * @var array<string, mixed>
     */
    protected $attributes = ['status' => 'uploaded', 'duplicate_mode' => 'skip', 'target' => 'clients', 'total_rows' => 0, 'imported_count' => 0, 'updated_count' => 0, 'skipped_count' => 0, 'failed_count' => 0];

    protected function casts(): array
    {
        return [
            'sheets' => 'array',
            'headers' => 'array',
            'mapping' => 'array',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    /**
     * @return HasMany<ImportRow, $this>
     */
    public function rows(): HasMany
    {
        return $this->hasMany(ImportRow::class)->orderBy('row_number');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
