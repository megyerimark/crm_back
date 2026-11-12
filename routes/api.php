<?php

use App\Http\Controllers\Api\V1\AccountController;
use App\Http\Controllers\Api\V1\ActivityController;
use App\Http\Controllers\Api\V1\Admin\AdminController;
use App\Http\Controllers\Api\V1\Admin\AnalyticsController;
use App\Http\Controllers\Api\V1\Admin\BillingAdminController;
use App\Http\Controllers\Api\V1\Admin\PlanAdminController;
use App\Http\Controllers\Api\V1\Admin\SupportAdminController;
use App\Http\Controllers\Api\V1\Admin\SystemController;
use App\Http\Controllers\Api\V1\AuditLogController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\AutomationRuleController;
use App\Http\Controllers\Api\V1\BillingController;
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
use App\Http\Controllers\Api\V1\PublicQuoteController;
use App\Http\Controllers\Api\V1\QuoteController;
use App\Http\Controllers\Api\V1\ReportController;
use App\Http\Controllers\Api\V1\SupportController;
use App\Http\Controllers\Api\V1\TaskController;
use App\Http\Controllers\Api\V1\TeamController;
use App\Http\Controllers\Api\V1\TwoFactorController;
use App\Http\Controllers\Api\V1\WorkspaceController;
use App\Http\Controllers\Webhooks\StripeWebhookController;
use Illuminate\Support\Facades\Route;

/* Stripe webhooks (signature verified by Cashier when STRIPE_WEBHOOK_SECRET is set). */
Route::post('stripe/webhook', [StripeWebhookController::class, 'handleWebhook'])->name('stripe.webhook');

Route::prefix('v1')->name('api.v1.')->group(function (): void {
    /* Public endpoints */
    Route::middleware('throttle:auth')->group(function (): void {
        Route::post('auth/register', [AuthController::class, 'register'])->name('auth.register');
        Route::post('auth/login', [AuthController::class, 'login'])->name('auth.login');
        Route::post('auth/two-factor/challenge', [TwoFactorController::class, 'challenge'])->name('auth.two-factor.challenge');
        Route::post('auth/forgot-password', [AccountController::class, 'forgotPassword'])->name('auth.forgot-password');
        Route::post('auth/reset-password', [AccountController::class, 'resetPassword'])->name('auth.reset-password');
    });

    /* Signed links from e-mails (validated on the relative URL, no login needed) */
    Route::middleware(['signed:relative', 'throttle:20,1'])->group(function (): void {
        Route::get('auth/email/verify/{id}/{hash}', [AccountController::class, 'verify'])->name('auth.verify');
        Route::get('auth/email-change/confirm/{id}/{hash}', [AccountController::class, 'confirmEmailChange'])->name('auth.email-change.confirm');
    });

    Route::get('plans', [WorkspaceController::class, 'plans'])->name('plans');
    Route::get('invitations/{token}', [InvitationController::class, 'show'])->name('invitations.show');

    Route::prefix('public/quotes/{token}')->name('public.quotes.')->middleware('throttle:public-quote')->group(function (): void {
        Route::get('/', [PublicQuoteController::class, 'show'])->name('show');
        Route::post('accept', [PublicQuoteController::class, 'accept'])->name('accept');
        Route::post('decline', [PublicQuoteController::class, 'decline'])->name('decline');
    });

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
        Route::post('auth/email/resend', [AccountController::class, 'resendVerification'])->middleware('throttle:3,1')->name('auth.verify.resend');
        Route::post('auth/email-change', [AccountController::class, 'requestEmailChange'])->middleware('throttle:5,1')->name('auth.email-change');
        Route::delete('auth/email-change', [AccountController::class, 'cancelEmailChange'])->name('auth.email-change.cancel');
        Route::get('auth/account/export', [AccountController::class, 'export'])->middleware('throttle:5,1')->name('auth.account.export');
        Route::delete('auth/account', [AccountController::class, 'destroy'])->middleware('throttle:5,1')->name('auth.account.destroy');
        Route::prefix('auth/two-factor')->name('auth.two-factor.')->middleware('throttle:10,1')->group(function (): void {
            Route::post('setup', [TwoFactorController::class, 'setup'])->name('setup');
            Route::post('confirm', [TwoFactorController::class, 'confirm'])->name('confirm');
            Route::post('recovery-codes', [TwoFactorController::class, 'recoveryCodes'])->name('recovery-codes');
            Route::delete('/', [TwoFactorController::class, 'disable'])->name('disable');
        });

        Route::get('workspaces', [WorkspaceController::class, 'index'])->name('workspaces.index');
        Route::post('workspaces', [WorkspaceController::class, 'store'])->middleware('verified-email')->name('workspaces.store');
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
            Route::get('analytics', AnalyticsController::class)->name('analytics');
            Route::get('subscriptions', [BillingAdminController::class, 'subscriptions'])->name('subscriptions');
            Route::get('invoices', [BillingAdminController::class, 'invoices'])->name('invoices');
            Route::post('invoices/{invoice}/retry', [BillingAdminController::class, 'retryInvoice'])->name('invoices.retry');
            Route::get('plans', [PlanAdminController::class, 'index'])->name('plans.index');
            Route::put('plans/{plan}', [PlanAdminController::class, 'update'])->name('plans.update');
            Route::delete('plans/{plan}', [PlanAdminController::class, 'reset'])->name('plans.reset');
            Route::get('system', [SystemController::class, 'show'])->name('system');
            Route::post('system/failed-jobs/{uuid}/retry', [SystemController::class, 'retryFailedJob'])->name('system.failed-jobs.retry');
            Route::delete('system/failed-jobs/{uuid}', [SystemController::class, 'forgetFailedJob'])->name('system.failed-jobs.forget');
            Route::get('support/tickets', [SupportAdminController::class, 'index'])->name('support.index');
            Route::get('support/tickets/{ticket}', [SupportAdminController::class, 'show'])->name('support.show');
            Route::post('support/tickets/{ticket}/messages', [SupportAdminController::class, 'reply'])->name('support.reply');
            Route::patch('support/tickets/{ticket}', [SupportAdminController::class, 'update'])->name('support.update');
        });

        /* Workspace scoped (X-Workspace-Id header) */
        Route::middleware(['verified-email', 'workspace'])->group(function (): void {
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

            Route::post('quotes/{quote}/send', [QuoteController::class, 'send'])->middleware('throttle:30,60')->name('quotes.send');
            Route::post('quotes/{quote}/mark', [QuoteController::class, 'mark'])->name('quotes.mark');
            Route::post('quotes/{quote}/duplicate', [QuoteController::class, 'duplicate'])->name('quotes.duplicate');
            Route::apiResource('quotes', QuoteController::class);
            Route::apiResource('activities', ActivityController::class)->only(['index', 'store', 'destroy']);

            Route::get('forms/{form}/submissions', [FormController::class, 'submissions'])->name('forms.submissions');
            Route::apiResource('forms', FormController::class);

            Route::get('imports/{import}/rows', [ImportController::class, 'rows'])->name('imports.rows');
            Route::post('imports/{import}/start', [ImportController::class, 'start'])->name('imports.start');
            Route::apiResource('imports', ImportController::class)->except('update');
            Route::patch('imports/{import}', [ImportController::class, 'update'])->name('imports.update');

            Route::apiResource('automations', AutomationRuleController::class)->parameters(['automations' => 'automationRule'])->except('show');
            Route::apiResource('custom-fields', CustomFieldController::class)->parameters(['custom-fields' => 'customField'])->except('show');

            Route::get('support/tickets', [SupportController::class, 'index'])->name('support.index');
            Route::post('support/tickets', [SupportController::class, 'store'])->middleware('throttle:10,60')->name('support.store');
            Route::get('support/tickets/{ticket}', [SupportController::class, 'show'])->name('support.show');
            Route::post('support/tickets/{ticket}/messages', [SupportController::class, 'reply'])->middleware('throttle:30,60')->name('support.reply');
            Route::post('support/tickets/{ticket}/close', [SupportController::class, 'close'])->name('support.close');

            Route::get('team/members', [TeamController::class, 'members'])->name('team.members');
            Route::get('team/invitations', [TeamController::class, 'invitations'])->name('team.invitations');

            Route::middleware('workspace.role:admin')->group(function (): void {
                Route::patch('workspace', [WorkspaceController::class, 'update'])->name('workspace.update');
                Route::get('workspace/downgrade-preview', [WorkspaceController::class, 'downgradePreview'])->name('workspace.downgrade-preview');
                Route::get('audit-logs', AuditLogController::class)->name('audit-logs');
                Route::patch('team/members/{user}', [TeamController::class, 'updateMember'])->name('team.members.update');
                Route::delete('team/members/{user}', [TeamController::class, 'removeMember'])->name('team.members.destroy');
                Route::post('team/invitations', [TeamController::class, 'invite'])->name('team.invitations.store');
                Route::post('team/owner', [TeamController::class, 'transferOwnership'])->name('team.owner');
                Route::delete('team/invitations/{invitation}', [TeamController::class, 'revoke'])->name('team.invitations.destroy');

                Route::prefix('billing')->name('billing.')->group(function (): void {
                    Route::get('/', [BillingController::class, 'show'])->name('show');
                    Route::put('details', [BillingController::class, 'updateDetails'])->name('details');
                    Route::post('checkout', [BillingController::class, 'checkout'])->name('checkout');
                    Route::post('portal', [BillingController::class, 'portal'])->name('portal');
                    Route::post('swap', [BillingController::class, 'swap'])->name('swap');
                    Route::post('cancel', [BillingController::class, 'cancel'])->name('cancel');
                    Route::post('resume', [BillingController::class, 'resume'])->name('resume');
                    Route::get('invoices/{invoice}/pdf', [BillingController::class, 'downloadInvoice'])->name('invoices.pdf');
                });
            });
        });
    });
});
