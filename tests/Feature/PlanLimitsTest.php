<?php

namespace Tests\Feature;

use App\Enums\Plan;
use App\Models\Client;
use App\Models\Form;
use App\Services\PlanService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PlanLimitsTest extends TestCase
{
    use RefreshDatabase;

    public function test_expired_trial_falls_back_to_free_without_losing_data(): void
    {
        $this->signIn('free');
        Client::factory()->count(120)->create(['workspace_id' => $this->workspace->id]);

        $this->assertSame(Plan::Free, app(PlanService::class)->effectivePlan($this->workspace));

        $this->getJson('/api/v1/clients?per_page=100')->assertOk()->assertJsonPath('meta.total', 120);
        $this->postJson('/api/v1/clients', ['name' => 'Új ügyfél'])->assertStatus(402)->assertJsonPath('code', 'plan_limit')->assertJsonPath('limit', 'clients');
        $this->getJson('/api/v1/clients/export')->assertOk();
    }

    public function test_trial_has_pro_limits(): void
    {
        $this->signIn('trial');
        Form::factory()->count(4)->create(['workspace_id' => $this->workspace->id]);

        $this->postJson('/api/v1/forms', ['name' => 'Ötödik'])->assertCreated();
        $this->postJson('/api/v1/forms', ['name' => 'Hatodik'])->assertStatus(402);
    }

    public function test_free_plan_has_one_form_and_no_custom_fields(): void
    {
        $this->signIn('free');

        $this->postJson('/api/v1/forms', [
            'name' => 'Egyedi',
            'fields' => [['key' => 'email', 'label' => 'E-mail', 'type' => 'email', 'required' => true], ['key' => 'budget', 'label' => 'Keret', 'type' => 'number']],
        ])->assertUnprocessable()->assertJsonValidationErrors('fields');

        $this->postJson('/api/v1/forms', ['name' => 'Első'])->assertCreated();
        $this->postJson('/api/v1/forms', ['name' => 'Második'])->assertStatus(402);
        $this->postJson('/api/v1/custom-fields', ['label' => 'Születésnap', 'type' => 'date'])->assertStatus(402);
    }

    public function test_excel_export_and_team_are_paid_features(): void
    {
        $this->signIn('free');

        $this->get('/api/v1/clients/export?format=xlsx')->assertStatus(402)->assertJsonPath('feature', 'excel_export');
        $this->postJson('/api/v1/team/invitations', ['email' => 'kolléga@example.com', 'role' => 'member'])->assertStatus(402)->assertJsonPath('feature', 'team');
    }

    public function test_downgrade_preview_lists_data_over_the_limits(): void
    {
        $this->signIn('pro');
        Client::factory()->count(101)->create(['workspace_id' => $this->workspace->id]);

        $this->getJson('/api/v1/workspace/downgrade-preview?plan=free')
            ->assertOk()->assertJsonPath('over_limits.clients.used', 101)->assertJsonPath('over_limits.clients.limit', 100);
    }

    public function test_workspace_endpoint_exposes_usage(): void
    {
        $this->signIn('business');

        $this->getJson('/api/v1/workspace')
            ->assertOk()
            ->assertJsonPath('subscription.effective_plan', 'business')
            ->assertJsonPath('subscription.limits.users', 5)
            ->assertJsonPath('subscription.usage.users.used', 1)
            ->assertJsonPath('subscription.features.team', true);
    }
}
