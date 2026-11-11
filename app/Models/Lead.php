<?php

namespace App\Models;

use App\Models\Concerns\BelongsToWorkspace;
use Database\Factories\LeadFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable(['workspace_id', 'name', 'company', 'email', 'phone', 'message', 'status', 'tags', 'source', 'form_id', 'utm_source', 'utm_medium', 'utm_campaign', 'utm_content', 'referrer', 'owner_id'])]
class Lead extends Model
{
    /** @use HasFactory<LeadFactory> */
    use BelongsToWorkspace, HasFactory, SoftDeletes;

    public const STATUSES = ['new', 'contacted', 'qualified', 'unqualified', 'converted'];

    public const SOURCES = ['manual', 'form', 'link', 'import'];

    /**
     * @var array<string, mixed>
     */
    protected $attributes = ['status' => 'new', 'source' => 'manual'];

    protected function casts(): array
    {
        return [
            'tags' => 'array',
            'converted_at' => 'datetime',
        ];
    }

    /**
     * Leads that still count against the "active leads" plan limit.
     *
     * @param  Builder<Lead>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->whereNotIn('status', ['converted', 'unqualified']);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    /**
     * @return BelongsTo<Form, $this>
     */
    public function form(): BelongsTo
    {
        return $this->belongsTo(Form::class);
    }

    /**
     * @return BelongsTo<Client, $this>
     */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    /**
     * @return HasMany<FormSubmission, $this>
     */
    public function submissions(): HasMany
    {
        return $this->hasMany(FormSubmission::class);
    }

    /**
     * @return HasMany<Task, $this>
     */
    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class);
    }

    /**
     * @return MorphMany<Activity, $this>
     */
    public function activities(): MorphMany
    {
        return $this->morphMany(Activity::class, 'subject');
    }

    public function isConverted(): bool
    {
        return $this->converted_at !== null;
    }
}
