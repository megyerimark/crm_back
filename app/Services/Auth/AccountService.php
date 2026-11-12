<?php

namespace App\Services\Auth;

use App\Enums\WorkspaceRole;
use App\Models\Activity;
use App\Models\AuditLog;
use App\Models\Client;
use App\Models\Deal;
use App\Models\Form;
use App\Models\FormSubmission;
use App\Models\Lead;
use App\Models\Task;
use App\Models\User;
use App\Models\Workspace;
use App\Notifications\AccountDeleted;
use App\Services\AuditLogger;
use App\Services\Billing\BillingGateway;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;

/**
 * GDPR: data export (right of access / portability) and account deletion (right to erasure).
 */
class AccountService
{
    public function __construct(protected BillingGateway $gateway, protected AuditLogger $audit) {}

    /**
     * @return Collection<int, Workspace>
     */
    public function ownedWorkspaces(User $user)
    {
        return $user->workspaces()->wherePivot('role', WorkspaceRole::Owner->value)->withCount('members')->get();
    }

    /**
     * @return array<string, mixed>
     */
    public function export(User $user): array
    {
        $owned = $this->ownedWorkspaces($user);

        return [
            'exported_at' => now()->toIso8601String(),
            'user' => $user->only(['id', 'name', 'email', 'locale', 'timezone', 'created_at', 'email_verified_at', 'last_login_at', 'notify_task_reminders', 'notify_daily_digest', 'notify_new_leads'])
                + ['two_factor_enabled' => $user->hasTwoFactorEnabled()],
            'memberships' => $user->workspaces()->get()->map(fn (Workspace $workspace) => ['workspace' => $workspace->name, 'role' => $workspace->pivot->role, 'joined_at' => $workspace->pivot->created_at]),
            'activity_log' => AuditLog::where('user_id', $user->id)->latest('id')->limit(1000)->get(['action', 'workspace_id', 'ip_address', 'created_at']),
            'owned_workspaces' => $owned->map(fn (Workspace $workspace) => [
                'workspace' => $workspace->only(['id', 'name', 'country', 'timezone', 'currency', 'billing_name', 'billing_email', 'billing_country', 'billing_postcode', 'billing_city', 'billing_address', 'billing_tax_number']),
                'clients' => Client::withoutGlobalScopes()->where('workspace_id', $workspace->id)->get(),
                'leads' => Lead::withoutGlobalScopes()->where('workspace_id', $workspace->id)->get(),
                'deals' => Deal::withoutGlobalScopes()->where('workspace_id', $workspace->id)->get(),
                'tasks' => Task::withoutGlobalScopes()->where('workspace_id', $workspace->id)->get(),
                'activities' => Activity::withoutGlobalScopes()->where('workspace_id', $workspace->id)->get(),
                'forms' => Form::withoutGlobalScopes()->where('workspace_id', $workspace->id)->get(),
                'form_submissions' => FormSubmission::withoutGlobalScopes()->where('workspace_id', $workspace->id)->get(),
            ]),
        ];
    }

    /**
     * Deletes the account and every workspace it solely owns. Paid subscriptions are cancelled immediately.
     * Issued invoices are kept (legal retention) with their buyer snapshot; the workspace link is cleared.
     */
    public function delete(User $user): void
    {
        $owned = $this->ownedWorkspaces($user);

        foreach ($owned as $workspace) {
            if ($workspace->members_count > 1) {
                throw ValidationException::withMessages(['account' => __('account.owner_has_members', ['workspace' => $workspace->name])]);
            }
        }

        $email = $user->email;
        $name = $user->name;
        $locale = $user->preferredLocale();

        foreach ($owned as $workspace) {
            if ($workspace->subscription('default')?->valid()) {
                $this->gateway->cancelNow($workspace);
            }
        }

        DB::transaction(function () use ($user, $owned): void {
            $this->audit->log('account.deleted', null, ['user_id' => $user->id, 'deleted_workspaces' => $owned->pluck('id')->all()], null);

            foreach ($owned as $workspace) {
                $workspace->delete();
            }

            $user->tokens()->delete();
            $user->notifications()->delete();
            $user->workspaces()->detach();
            DB::table('password_reset_tokens')->where('email', $user->email)->delete();
            $user->delete();
        });

        Notification::route('mail', $email)->notify((new AccountDeleted($name))->locale($locale));
    }
}
