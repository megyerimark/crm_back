<?php

namespace App\Models;

use App\Models\Concerns\BelongsToWorkspace;
use Database\Factories\FormFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable(['workspace_id', 'name', 'slug', 'status', 'locale', 'title', 'description', 'fields', 'thank_you_message', 'privacy_url', 'privacy_version', 'ask_marketing_consent', 'notify_owner', 'notify_emails', 'show_branding', 'allowed_origins', 'default_owner_id'])]
class Form extends Model
{
    /** @use HasFactory<FormFactory> */
    use BelongsToWorkspace, HasFactory, SoftDeletes;

    /** Built-in fields that map directly onto lead columns. */
    public const BASE_FIELDS = ['name', 'email', 'phone', 'company', 'message'];

    public const FIELD_TYPES = ['text', 'email', 'tel', 'textarea', 'number', 'select', 'checkbox', 'date'];

    /**
     * @var array<string, mixed>
     */
    protected $attributes = ['status' => 'draft', 'locale' => 'hu', 'notify_owner' => true, 'show_branding' => true, 'ask_marketing_consent' => false, 'submissions_count' => 0];

    protected function casts(): array
    {
        return [
            'fields' => 'array',
            'notify_emails' => 'array',
            'allowed_origins' => 'array',
            'ask_marketing_consent' => 'boolean',
            'notify_owner' => 'boolean',
            'show_branding' => 'boolean',
        ];
    }

    /**
     * @return HasMany<FormSubmission, $this>
     */
    public function submissions(): HasMany
    {
        return $this->hasMany(FormSubmission::class);
    }

    /**
     * @return HasMany<Lead, $this>
     */
    public function leads(): HasMany
    {
        return $this->hasMany(Lead::class);
    }

    public function isPublished(): bool
    {
        return $this->status === 'published';
    }

    /**
     * @return list<array{key: string, label: string, type: string, required?: bool, options?: list<string>}>
     */
    public static function defaultFields(): array
    {
        return [
            ['key' => 'name', 'label' => 'Név', 'type' => 'text', 'required' => true],
            ['key' => 'email', 'label' => 'E-mail', 'type' => 'email', 'required' => true],
            ['key' => 'phone', 'label' => 'Telefon', 'type' => 'tel', 'required' => false],
            ['key' => 'message', 'label' => 'Üzenet', 'type' => 'textarea', 'required' => false],
        ];
    }
}
