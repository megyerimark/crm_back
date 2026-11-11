<?php

use App\Http\Controllers\Api\V1\ActivityController;
use App\Http\Controllers\Api\V1\Admin\AdminController;
use App\Http\Controllers\Api\V1\AuditLogController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\AutomationRuleController;
use App\Http\Controllers\Api\V1\ClientController;
use App\Http\Controllers\Api\V1\CustomFieldController;
use App\Http\Controllers\Api\V1\DashboardController;
use App\Http\Controllers\Api\V1\DealController;
use App\Http\Controllers\Api\V1\FormController;
use App\Http\Controllers\Api\V1\ImportController;
use App\Http\Controllers\Api\V1\InvitationController;
use App\Http\Controllers\Api\V1\LeadController;
use App\Http\Controllers\Api\V1\NotificationController;
use App\Http\Controllers\Api\V1\PipelineController;
use App\Http\Controllers\Api\V1\PublicFormController;
use App\Http\Controllers\Api\V1\ReportController;
use App\Http\Controllers\Api\V1\TaskController;
use App\Http\Controllers\Api\V1\TeamController;
use App\Http\Controllers\Api\V1\WorkspaceController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->name('api.v1.')->group(function (): void {
    /* Public endpoints */
    Route::middleware('throttle:auth')->group(function (): void {
        Route::post('auth/register', [AuthController::class, 'register'])->name('auth.register');
        Route::post('auth/login', [AuthController::class, 'login'])->name('auth.login');
    });

    Route::get('plans', [WorkspaceController::class, 'plans'])->name('plans');
    Route::get('invitations/{token}', [InvitationController::class, 'show'])->name('invitations.show');

    Route::prefix('public/forms/{slug}')->name('public.forms.')->group(function (): void {
        Route::get('/', [PublicFormController::class, 'show'])->middleware('throttle:60,1')->name('show');
        Route::post('submissions', [PublicFormController::class, 'submit'])->middleware('throttle:public-form')->name('submit');
    });

    /* Authenticated, no workspace context */
    Route::middleware(['auth:sanctum', 'throttle:api'])->group(function (): void {
        Route::get('auth/me', [AuthController::class, 'me'])->name('auth.me');
        Route::post('auth/logout', [AuthController::class, 'logout'])->name('auth.logout');
        Route::patch('auth/profile', [AuthController::class, 'updateProfile'])->name('auth.profile');
        Route::put('auth/password', [AuthController::class, 'updatePassword'])->name('auth.password');

        Route::get('workspaces', [WorkspaceController::class, 'index'])->name('workspaces.index');
        Route::post('workspaces', [WorkspaceController::class, 'store'])->name('workspaces.store');
        Route::post('workspaces/{workspace}/switch', [WorkspaceController::class, 'switch'])->whereNumber('workspace')->name('workspaces.switch');
        Route::post('invitations/{token}/accept', [InvitationController::class, 'accept'])->name('invitations.accept');

        /* Super Admin console */
        Route::prefix('admin')->name('admin.')->middleware('super-admin')->group(function (): void {
            Route::get('stats', [AdminController::class, 'stats'])->name('stats');
            Route::get('workspaces', [AdminController::class, 'workspaces'])->name('workspaces.index');
            Route::get('workspaces/{workspace}', [AdminController::class, 'showWorkspace'])->name('workspaces.show');
            Route::patch('workspaces/{workspace}', [AdminController::class, 'updateWorkspace'])->name('workspaces.update');
            Route::get('users', [AdminController::class, 'users'])->name('users.index');
            Route::get('audit-logs', [AdminController::class, 'auditLogs'])->name('audit-logs');
        });

        /* Workspace scoped (X-Workspace-Id header) */
        Route::middleware('workspace')->group(function (): void {
            Route::get('workspace', [WorkspaceController::class, 'current'])->name('workspace.current');
            Route::get('dashboard', DashboardController::class)->name('dashboard');
            Route::get('reports', ReportController::class)->name('reports');
            Route::get('notifications', [NotificationController::class, 'index'])->name('notifications.index');
            Route::post('notifications/read', [NotificationController::class, 'markAllRead'])->name('notifications.read');

            Route::get('clients/export', [ClientController::class, 'export'])->name('clients.export');
            Route::apiResource('clients', ClientController::class);

            Route::post('leads/{lead}/convert', [LeadController::class, 'convert'])->name('leads.convert');
            Route::apiResource('leads', LeadController::class);

            Route::apiResource('pipelines', PipelineController::class)->except('show');
            Route::post('deals/{deal}/move', [DealController::class, 'move'])->name('deals.move');
            Route::apiResource('deals', DealController::class);

            Route::apiResource('tasks', TaskController::class);
            Route::apiResource('activities', ActivityController::class)->only(['index', 'store', 'destroy']);

            Route::get('forms/{form}/submissions', [FormController::class, 'submissions'])->name('forms.submissions');
            Route::apiResource('forms', FormController::class);

            Route::get('imports/{import}/rows', [ImportController::class, 'rows'])->name('imports.rows');
            Route::post('imports/{import}/start', [ImportController::class, 'start'])->name('imports.start');
            Route::apiResource('imports', ImportController::class)->except('update');
            Route::patch('imports/{import}', [ImportController::class, 'update'])->name('imports.update');

            Route::apiResource('automations', AutomationRuleController::class)->parameters(['automations' => 'automationRule'])->except('show');
            Route::apiResource('custom-fields', CustomFieldController::class)->parameters(['custom-fields' => 'customField'])->except('show');

            Route::get('team/members', [TeamController::class, 'members'])->name('team.members');
            Route::get('team/invitations', [TeamController::class, 'invitations'])->name('team.invitations');

            Route::middleware('workspace.role:admin')->group(function (): void {
                Route::patch('workspace', [WorkspaceController::class, 'update'])->name('workspace.update');
                Route::get('workspace/downgrade-preview', [WorkspaceController::class, 'downgradePreview'])->name('workspace.downgrade-preview');
                Route::get('audit-logs', AuditLogController::class)->name('audit-logs');
                Route::patch('team/members/{user}', [TeamController::class, 'updateMember'])->name('team.members.update');
                Route::delete('team/members/{user}', [TeamController::class, 'removeMember'])->name('team.members.destroy');
                Route::post('team/invitations', [TeamController::class, 'invite'])->name('team.invitations.store');
                Route::delete('team/invitations/{invitation}', [TeamController::class, 'revoke'])->name('team.invitations.destroy');
            });
        });
    });
});
