<?php

namespace App\Models;

use App\Enums\WorkspaceRole;
use App\Notifications\ResetPasswordLink;
use App\Notifications\VerifyEmailAddress;
use Database\Factories\UserFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Contracts\Translation\HasLocalePreference;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\URL;
use Laravel\Sanctum\HasApiTokens;

#[Fillable(['name', 'email', 'password', 'locale', 'theme', 'timezone', 'current_workspace_id', 'notify_task_reminders', 'notify_daily_digest', 'notify_new_leads', 'notify_quote_updates'])]
#[Hidden(['password', 'remember_token', 'two_factor_secret', 'two_factor_recovery_codes', 'two_factor_last_step'])]
class User extends Authenticatable implements HasLocalePreference, MustVerifyEmail
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'last_login_at' => 'datetime',
            'password' => 'hashed',
            'is_super_admin' => 'boolean',
            'two_factor_confirmed_at' => 'datetime',
            'last_digest_sent_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'notify_task_reminders' => 'boolean',
            'notify_daily_digest' => 'boolean',
            'notify_new_leads' => 'boolean',
            'notify_quote_updates' => 'boolean',
        ];
    }

    /**
     * @return BelongsToMany<Workspace, $this>
     */
    public function workspaces(): BelongsToMany
    {
        return $this->belongsToMany(Workspace::class, 'workspace_user')->withPivot('role')->withTimestamps();
    }

    public function roleIn(Workspace|int $workspace): ?WorkspaceRole
    {
        $workspaceId = $workspace instanceof Workspace ? $workspace->id : $workspace;
        $role = $this->workspaces()->whereKey($workspaceId)->first()?->pivot?->role;

        return $role ? WorkspaceRole::from($role) : null;
    }

    public function preferredLocale(): string
    {
        return $this->locale ?: 'hu';
    }

    public function hasTwoFactorEnabled(): bool
    {
        return $this->two_factor_secret !== null && $this->two_factor_confirmed_at !== null;
    }

    /**
     * The link opens the frontend, which calls the signed API route (signature is checked on a relative URL).
     */
    public function sendEmailVerificationNotification(): void
    {
        $this->notify(new VerifyEmailAddress($this->frontendSignedUrl('/verify-email/confirm', 'api.v1.auth.verify', [
            'id' => $this->getKey(),
            'hash' => sha1($this->getEmailForVerification()),
        ])));
    }

    public function sendPasswordResetNotification(#[\SensitiveParameter] $token): void
    {
        $url = rtrim(config('app.frontend_url'), '/').'/reset-password?'.http_build_query(['token' => $token, 'email' => $this->email]);

        $this->notify(new ResetPasswordLink($url, (int) config('auth.passwords.users.expire', 60)));
    }

    /**
     * Builds a frontend URL carrying the parameters of a temporary signed (relative) API route.
     *
     * @param  array<string, mixed>  $parameters
     */
    public function frontendSignedUrl(string $frontendPath, string $route, array $parameters, int $minutes = 1440): string
    {
        $signed = URL::temporarySignedRoute($route, now()->addMinutes($minutes), $parameters, absolute: false);
        parse_str((string) parse_url($signed, PHP_URL_QUERY), $query);

        return rtrim(config('app.frontend_url'), '/').$frontendPath.'?'.http_build_query($parameters + $query);
    }
}
