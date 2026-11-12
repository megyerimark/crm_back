<?php

namespace Tests\Feature;

use App\Jobs\IssueInvoice;
use App\Models\AuditLog;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PlatformAdminTest extends TestCase
{
    use RefreshDatabase;

    protected function actAsAdmin(): User
    {
        $admin = User::factory()->superAdmin()->create();
        Sanctum::actingAs($admin);

        return $admin;
    }

    public function test_super_admin_must_have_two_factor_enabled(): void
    {
        config(['admin.require_two_factor' => true]);
        Sanctum::actingAs(User::factory()->create(['is_super_admin' => true]));

        $this->getJson('/api/v1/admin/stats')->assertForbidden()->assertJsonPath('code', 'two_factor_required');

        $this->actAsAdmin();
        $this->getJson('/api/v1/admin/stats')->assertOk();
    }

    public function test_every_admin_endpoint_rejects_workspace_owners(): void
    {
        $this->signIn('business');

        foreach (['stats', 'workspaces', 'users', 'audit-logs', 'analytics', 'subscriptions', 'invoices', 'plans', 'system', 'support/tickets'] as $endpoint) {
            $this->getJson("/api/v1/admin/{$endpoint}")->assertForbidden();
        }

        $this->putJson('/api/v1/admin/plans/free', ['limits' => ['clients' => 100000]])->assertForbidden();
    }

    public function test_analytics_reports_signups_activity_mrr_and_conversion(): void
    {
        $this->signIn('pro');
        $this->workspace->forceFill(['billing_interval' => 'yearly'])->save();
        DB::table('user_activity_days')->insert(['user_id' => $this->user->id, 'day' => now()->toDateString()]);
        $this->actAsAdmin();

        $response = $this->getJson('/api/v1/admin/analytics?days=30')->assertOk();

        $response->assertJsonPath('users.dau', 1)
            ->assertJsonPath('workspaces.paid', 1)
            ->assertJsonPath('revenue.mrr', (int) round(29900 / 12));
        $this->assertCount(30, $response->json('series'));
    }

    public function test_api_requests_record_daily_activity(): void
    {
        $this->signIn();

        $this->getJson('/api/v1/clients')->assertOk();
        $this->getJson('/api/v1/clients')->assertOk();

        $this->assertSame(1, DB::table('user_activity_days')->where('user_id', $this->user->id)->count());
        $this->assertNotNull($this->user->fresh()->last_seen_at);
    }

    public function test_plan_overrides_change_limits_immediately_and_are_audited(): void
    {
        $this->signIn('free');
        Client::factory()->count(100)->create(['workspace_id' => $this->workspace->id]);
        $this->postJson('/api/v1/clients', ['name' => 'Százegyedik'])->assertStatus(402);

        $owner = $this->user;
        $admin = $this->actAsAdmin();
        $this->putJson('/api/v1/admin/plans/free', ['limits' => ['clients' => 150], 'prices' => ['monthly' => 0, 'yearly' => 0]])
            ->assertOk()->assertJsonPath('data.effective.limits.clients', 150);
        $this->putJson('/api/v1/admin/plans/pro', ['limits' => ['unknown_limit' => 5, 'forms' => 7]])->assertOk()->assertJsonMissingPath('data.effective.limits.unknown_limit');
        $this->putJson('/api/v1/admin/plans/pro', ['stripe_prices' => ['monthly' => 'not-a-price']])->assertUnprocessable();

        $this->assertTrue(AuditLog::where('action', 'admin.plan.updated')->where('user_id', $admin->id)->where('is_admin_action', true)->exists());

        Sanctum::actingAs($owner);
        $this->postJson('/api/v1/clients', ['name' => 'Százegyedik'])->assertCreated();
        $this->getJson('/api/v1/plans')->assertJsonPath('data.1.limits.forms', 7);

        $this->actAsAdmin();
        $this->deleteJson('/api/v1/admin/plans/free')->assertOk()->assertJsonPath('data.effective.limits.clients', 100);
    }

    public function test_workspace_filters_for_trials_and_paid_plans(): void
    {
        $this->signIn('trial');
        $trial = $this->workspace;
        $trial->forceFill(['trial_ends_at' => now()->addDays(2)])->save();
        $this->signIn('business', User::factory()->create());
        $paid = $this->workspace;
        $this->actAsAdmin();

        $this->getJson('/api/v1/admin/workspaces?status=expiring')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $trial->id);
        $this->getJson('/api/v1/admin/workspaces?status=paid')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $paid->id);
    }

    public function test_subscriptions_invoices_and_invoice_retry(): void
    {
        Queue::fake();
        $this->signIn('pro');
        $invoice = Invoice::create([
            'workspace_id' => $this->workspace->id, 'stripe_invoice_id' => 'in_f', 'currency' => 'HUF', 'net_amount' => 2354, 'vat_amount' => 636,
            'gross_amount' => 2990, 'vat_key' => '27', 'buyer' => ['name' => 'Teszt'], 'status' => 'failed',
        ]);
        $invoice->forceFill(['error' => 'Hibás adószám'])->save();
        $this->actAsAdmin();

        $this->getJson('/api/v1/admin/subscriptions')->assertOk()->assertJsonPath('data.0.source', 'manual')->assertJsonPath('data.0.plan', 'pro');
        $this->getJson('/api/v1/admin/invoices?status=failed')->assertOk()->assertJsonPath('data.0.error', 'Hibás adószám')->assertJsonPath('totals.failed', 1);

        $this->postJson("/api/v1/admin/invoices/{$invoice->id}/retry")->assertOk()->assertJsonPath('status', 'pending');
        Queue::assertPushed(IssueInvoice::class);
    }

    public function test_system_health_lists_checks_and_failed_jobs(): void
    {
        DB::table('failed_jobs')->insert([
            'uuid' => 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee', 'connection' => 'database', 'queue' => 'default',
            'payload' => json_encode(['displayName' => 'App\\Jobs\\IssueInvoice']), 'exception' => "RuntimeException: boom\n#0 trace", 'failed_at' => now(),
        ]);
        $this->actAsAdmin();

        $response = $this->getJson('/api/v1/admin/system')->assertOk();

        $this->assertSame('ok', collect($response->json('checks'))->firstWhere('key', 'database')['status']);
        $response->assertJsonPath('failed_jobs.0.job', 'App\\Jobs\\IssueInvoice')->assertJsonPath('failed_jobs.0.exception', 'RuntimeException: boom');

        $this->deleteJson('/api/v1/admin/system/failed-jobs/aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee')->assertOk();
        $this->assertSame(0, DB::table('failed_jobs')->count());
    }

    public function test_admin_reads_are_audited(): void
    {
        $this->signIn();
        $workspace = $this->workspace;
        $this->actAsAdmin();

        $this->getJson("/api/v1/admin/workspaces/{$workspace->id}")->assertOk();

        $this->assertTrue(AuditLog::where('action', 'admin.workspace.viewed')->where('workspace_id', $workspace->id)->where('is_admin_action', true)->exists());
    }
}
