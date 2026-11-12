<?php

namespace App\Models;

use Database\Factories\WorkspaceFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Laravel\Cashier\Billable;

#[Fillable(['name', 'slug', 'owner_id', 'country', 'timezone', 'currency', 'locale', 'plan', 'trial_ends_at', 'subscription_status', 'subscription_ends_at', 'billing_interval', 'intended_plan', 'billing_name', 'billing_email', 'billing_country', 'billing_postcode', 'billing_city', 'billing_address', 'billing_tax_number', 'billing_eu_vat_number'])]
class Workspace extends Model
{
    /** @use HasFactory<WorkspaceFactory> */
    use Billable, HasFactory;

    protected function casts(): array
    {
        return [
            'trial_ends_at' => 'datetime',
            'subscription_ends_at' => 'datetime',
            'suspended_at' => 'datetime',
            'trial_reminder_sent_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    /**
     * @return BelongsToMany<User, $this>
     */
    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'workspace_user')->withPivot('role')->withTimestamps();
    }

    /**
     * @return HasMany<Invitation, $this>
     */
    public function invitations(): HasMany
    {
        return $this->hasMany(Invitation::class);
    }

    /**
     * @return HasMany<Invoice, $this>
     */
    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class)->latest('id');
    }

    /**
     * Billing details required on a Hungarian invoice (buyer name and full address).
     */
    public function hasCompleteBillingDetails(): bool
    {
        return filled($this->billing_name) && filled($this->billing_country) && filled($this->billing_postcode)
            && filled($this->billing_city) && filled($this->billing_address);
    }

    public function stripeName(): ?string
    {
        return $this->billing_name ?: $this->name;
    }

    public function stripeEmail(): ?string
    {
        return $this->billing_email ?: $this->owner?->email;
    }

    /**
     * @return array<string, string|null>
     */
    public function stripeAddress(): array
    {
        return [
            'country' => $this->billing_country,
            'postal_code' => $this->billing_postcode,
            'city' => $this->billing_city,
            'line1' => $this->billing_address,
        ];
    }

    /**
     * @return list<string>
     */
    public function stripePreferredLocales(): array
    {
        return [$this->locale ?: 'hu'];
    }

    /**
     * @return array<string, string>
     */
    public function stripeMetadata(): array
    {
        return ['workspace_id' => (string) $this->id];
    }
}
