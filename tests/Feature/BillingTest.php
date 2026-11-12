<?php

namespace Tests\Feature;

use App\Enums\WorkspaceRole;
use App\Jobs\IssueInvoice;
use App\Models\Invoice;
use App\Models\Workspace;
use App\Notifications\TrialEndingSoon;
use App\Services\AuditLogger;
use App\Services\Billing\BillingGateway;
use App\Services\Billing\SzamlazzHuClient;
use App\Services\PlanService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\Fakes\FakeBillingGateway;
use Tests\TestCase;

class BillingTest extends TestCase
{
    use RefreshDatabase;

    protected FakeBillingGateway $gateway;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'cashier.secret' => 'sk_test_fake',
            'cashier.webhook.secret' => null,
            'billing.allow_unsigned_webhooks' => true,
            'plans.plans.pro.stripe_prices' => ['monthly' => 'price_pro_m', 'yearly' => 'price_pro_y'],
            'plans.plans.business.stripe_prices' => ['monthly' => 'price_biz_m', 'yearly' => 'price_biz_y'],
            'billing.szamlazz.enabled' => true,
            'billing.szamlazz.agent_key' => 'agent-key',
            'billing.vat.key' => '27',
            'billing.vat.prices_include_vat' => true,
        ]);

        $this->gateway = new FakeBillingGateway;
        $this->app->instance(BillingGateway::class, $this->gateway);
    }

    /**
     * @return array<string, string>
     */
    protected function billingDetails(): array
    {
        return [
            'billing_name' => 'Teszt Kft.',
            'billing_email' => 'szamla@teszt.hu',
            'billing_country' => 'HU',
            'billing_postcode' => '1051',
            'billing_city' => 'Budapest',
            'billing_address' => 'Fő utca 1.',
            'billing_tax_number' => '12345678-2-41',
        ];
    }

    protected function subscribe(string $price = 'price_pro_m', array $attributes = []): void
    {
        $this->workspace->forceFill(['stripe_id' => 'cus_test'])->save();
        $this->workspace->subscriptions()->create($attributes + [
            'type' => 'default', 'stripe_id' => 'sub_test', 'stripe_status' => 'active', 'stripe_price' => $price, 'quantity' => 1,
        ]);
    }

    /**
     * @param  array<string, mixed>  $object
     */
    protected function webhook(string $type, array $object): TestResponse
    {
        return $this->postJson('/api/stripe/webhook', ['id' => 'evt_'.uniqid(), 'type' => $type, 'data' => ['object' => $object]]);
    }

    /**
     * @return array<string, mixed>
     */
    protected function subscriptionObject(string $price, array $overrides = []): array
    {
        return $overrides + [
            'id' => 'sub_test',
            'customer' => 'cus_test',
            'status' => 'active',
            'metadata' => [],
            'items' => ['data' => [['id' => 'si_test', 'quantity' => 1, 'price' => ['id' => $price, 'product' => 'prod_test']]]],
        ];
    }

    public function test_checkout_requires_billing_details_and_keeps_the_trial(): void
    {
        $this->signIn('trial');

        $this->postJson('/api/v1/billing/checkout', ['plan' => 'pro', 'interval' => 'yearly'])
            ->assertUnprocessable()->assertJsonValidationErrors('billing');

        $this->putJson('/api/v1/billing/details', $this->billingDetails())->assertOk()->assertJsonPath('status.billing_details_complete', true);

        $this->postJson('/api/v1/billing/checkout', ['plan' => 'pro', 'interval' => 'yearly'])
            ->assertOk()->assertJsonPath('url', 'https://checkout.stripe.test/session/'.$this->workspace->id);

        $call = collect($this->gateway->calls)->firstWhere('method', 'checkout');
        $this->assertSame('price_pro_y', $call['price']);
        $this->assertTrue($call['trial_ends_at']->eq($this->workspace->fresh()->trial_ends_at));
        $this->assertSame('pro', $this->workspace->fresh()->intended_plan);
    }

    public function test_billing_details_validate_hungarian_tax_number(): void
    {
        $this->signIn();

        $this->putJson('/api/v1/billing/details', ['billing_tax_number' => '123'] + $this->billingDetails())
            ->assertUnprocessable()->assertJsonValidationErrors('billing_tax_number');
    }

    public function test_checkout_is_unavailable_until_stripe_is_configured(): void
    {
        config(['cashier.secret' => null]);
        $this->signIn();
        $this->putJson('/api/v1/billing/details', $this->billingDetails());

        $this->postJson('/api/v1/billing/checkout', ['plan' => 'pro', 'interval' => 'monthly'])
            ->assertStatus(503)->assertJsonPath('code', 'billing_not_configured');
    }

    public function test_only_admins_manage_billing(): void
    {
        $this->signIn('business');
        $this->actingAs($this->addMember(WorkspaceRole::Member), 'sanctum');

        $this->getJson('/api/v1/billing')->assertForbidden();
        $this->postJson('/api/v1/billing/checkout', ['plan' => 'pro', 'interval' => 'monthly'])->assertForbidden();
    }

    public function test_subscription_webhooks_drive_the_effective_plan(): void
    {
        $this->signIn('free');
        $this->workspace->forceFill(['stripe_id' => 'cus_test'])->save();
        $plans = app(PlanService::class);

        $this->webhook('customer.subscription.created', $this->subscriptionObject('price_biz_y'))->assertOk();

        $workspace = $this->workspace->fresh();
        $this->assertSame('business', $workspace->plan);
        $this->assertSame('yearly', $workspace->billing_interval);
        $this->assertSame('business', $plans->effectivePlan($workspace)->value);

        /* Cancelled at period end: still Business until the end of the paid period. */
        $endsAt = now()->addDays(10)->getTimestamp();
        $this->webhook('customer.subscription.updated', $this->subscriptionObject('price_biz_y', ['cancel_at' => $endsAt]))->assertOk();
        $this->assertSame('business', $plans->effectivePlan($this->workspace->fresh())->value);
        $this->getJson('/api/v1/billing')->assertJsonPath('status.on_grace_period', true);

        $this->webhook('customer.subscription.deleted', $this->subscriptionObject('price_biz_y', ['status' => 'canceled']))->assertOk();
        $this->assertSame('free', $plans->effectivePlan($this->workspace->fresh())->value);
        $this->assertSame('canceled', $this->workspace->fresh()->subscription_status);
    }

    public function test_checkout_redirect_alone_never_grants_a_paid_plan(): void
    {
        $this->signIn('free');
        $this->putJson('/api/v1/billing/details', $this->billingDetails());

        $this->postJson('/api/v1/billing/checkout', ['plan' => 'business', 'interval' => 'monthly'])->assertOk();
        $this->getJson('/api/v1/billing?checkout=success')->assertOk()->assertJsonPath('status.has_subscription', false);

        $this->getJson('/api/v1/workspace')->assertJsonPath('subscription.effective_plan', 'free');
        $this->postJson('/api/v1/team/invitations', ['email' => 'x@example.com', 'role' => 'member'])->assertStatus(402);
    }

    public function test_unsigned_webhooks_are_rejected_when_no_secret_is_configured(): void
    {
        config(['billing.allow_unsigned_webhooks' => false]);
        $this->signIn('free');
        $this->workspace->forceFill(['stripe_id' => 'cus_test'])->save();

        $this->webhook('customer.subscription.created', $this->subscriptionObject('price_biz_m'))->assertForbidden();

        $this->assertSame('free', app(PlanService::class)->effectivePlan($this->workspace->fresh())->value);
        $this->assertSame(0, $this->workspace->subscriptions()->count());
    }

    public function test_webhook_signature_is_verified(): void
    {
        config(['cashier.webhook.secret' => 'whsec_test', 'billing.allow_unsigned_webhooks' => false]);
        $this->signIn('free');
        $this->workspace->forceFill(['stripe_id' => 'cus_test'])->save();
        $body = json_encode(['id' => 'evt_sig', 'type' => 'customer.subscription.created', 'data' => ['object' => $this->subscriptionObject('price_biz_m')]]);
        $timestamp = time();

        $this->call('POST', '/api/stripe/webhook', [], [], [], ['HTTP_STRIPE_SIGNATURE' => "t={$timestamp},v1=forged", 'CONTENT_TYPE' => 'application/json'], $body)->assertForbidden();
        $this->assertSame('free', $this->workspace->fresh()->plan);

        $signature = hash_hmac('sha256', "{$timestamp}.{$body}", 'whsec_test');
        $this->call('POST', '/api/stripe/webhook', [], [], [], ['HTTP_STRIPE_SIGNATURE' => "t={$timestamp},v1={$signature}", 'CONTENT_TYPE' => 'application/json'], $body)->assertOk();
        $this->assertSame('business', app(PlanService::class)->effectivePlan($this->workspace->fresh())->value);
    }

    public function test_paid_invoice_issues_a_szamlazz_e_invoice_once(): void
    {
        Storage::fake('local');
        Http::fake(['www.szamlazz.hu/*' => Http::response('%PDF-1.4 fake', 200, ['szlahu_szamlaszam' => 'UFL-2026-1', 'Content-Type' => 'application/pdf'])]);
        $this->signIn();
        $this->workspace->update($this->billingDetails());
        $this->subscribe('price_pro_m');

        $payload = [
            'id' => 'in_test', 'customer' => 'cus_test', 'number' => 'ABC-0001', 'amount_paid' => 299000, 'currency' => 'huf',
            'status_transitions' => ['paid_at' => now()->getTimestamp()],
            'lines' => ['data' => [['price' => ['id' => 'price_pro_m'], 'period' => ['start' => now()->getTimestamp(), 'end' => now()->addMonth()->getTimestamp()]]]],
        ];

        $this->webhook('invoice.paid', $payload)->assertOk();
        $this->webhook('invoice.paid', $payload)->assertOk();

        $invoice = Invoice::sole();
        $this->assertSame('issued', $invoice->status);
        $this->assertSame('UFL-2026-1', $invoice->number);
        $this->assertSame(['2354.00', '636.00', '2990.00'], [$invoice->net_amount, $invoice->vat_amount, $invoice->gross_amount]);
        Storage::disk('local')->assertExists($invoice->pdf_path);
        Http::assertSentCount(1);

        Http::assertSent(function ($request) {
            $xml = $request->data()[0]['contents'] ?? '';

            return str_contains($xml, '<szamlaagentkulcs>agent-key</szamlaagentkulcs>')
                && str_contains($xml, '<adoalany>1</adoalany>')
                && str_contains($xml, '<adoszam>12345678-2-41</adoszam>')
                && str_contains($xml, '<afakulcs>27</afakulcs>')
                && str_contains($xml, '<bruttoErtek>2990</bruttoErtek>')
                && str_contains($xml, '<megnevezes>VállalkozóOS Pro előfizetés (havi)</megnevezes>');
        });

        $this->get("/api/v1/billing/invoices/{$invoice->id}/pdf")->assertOk();
        $this->getJson('/api/v1/billing')->assertJsonPath('invoices.0.number', 'UFL-2026-1');
    }

    public function test_zero_amount_trial_invoices_are_not_issued(): void
    {
        Queue::fake();
        $this->signIn();
        $this->subscribe();

        $this->webhook('invoice.paid', ['id' => 'in_zero', 'customer' => 'cus_test', 'amount_paid' => 0, 'currency' => 'huf', 'lines' => ['data' => []]])->assertOk();

        $this->assertSame(0, Invoice::count());
        Queue::assertNothingPushed();
    }

    public function test_failed_invoice_is_kept_for_retry(): void
    {
        Http::fake(['www.szamlazz.hu/*' => Http::response('', 200, ['szlahu_error_code' => '57', 'szlahu_error' => urlencode('Hibás adószám')])]);
        $this->signIn();
        $this->workspace->update($this->billingDetails());
        $this->subscribe();
        $invoice = Invoice::create([
            'workspace_id' => $this->workspace->id, 'stripe_invoice_id' => 'in_x', 'currency' => 'HUF', 'net_amount' => 2354, 'vat_amount' => 636,
            'gross_amount' => 2990, 'vat_key' => '27', 'plan' => 'pro', 'interval' => 'monthly', 'buyer' => ['name' => 'X', 'postcode' => '1', 'city' => 'B', 'address' => 'A'],
        ]);

        try {
            (new IssueInvoice($invoice->id))->handle(app(SzamlazzHuClient::class), app(AuditLogger::class));
            $this->fail('Expected an exception.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('Hibás adószám', $exception->getMessage());
        }

        $this->assertSame('failed', $invoice->fresh()->status);
        $this->assertNull($invoice->fresh()->number);
    }

    public function test_swap_cancel_and_resume(): void
    {
        $this->signIn('free');
        $this->subscribe('price_pro_m');
        $this->workspace->forceFill(['plan' => 'pro', 'subscription_status' => 'active'])->save();

        $this->postJson('/api/v1/billing/swap', ['plan' => 'business', 'interval' => 'yearly'])
            ->assertOk()->assertJsonPath('subscription.effective_plan', 'business')->assertJsonPath('status.interval', 'yearly');

        $this->postJson('/api/v1/billing/cancel')->assertOk()->assertJsonPath('status.on_grace_period', true)->assertJsonPath('subscription.effective_plan', 'business');
        $this->postJson('/api/v1/billing/resume')->assertOk()->assertJsonPath('status.on_grace_period', false);
        $this->postJson('/api/v1/billing/checkout', ['plan' => 'pro', 'interval' => 'monthly'])->assertUnprocessable();
    }

    public function test_registration_remembers_the_chosen_plan(): void
    {
        $this->postJson('/api/v1/auth/register', [
            'name' => 'Vevő Vera', 'email' => 'vera@example.com', 'password' => 'jelszo1234', 'password_confirmation' => 'jelszo1234',
            'workspace_name' => 'Vera Stúdió', 'accept_terms' => true, 'plan' => 'business', 'interval' => 'yearly',
        ])->assertCreated()->assertJsonPath('workspaces.0.intended_plan', 'business')->assertJsonPath('workspaces.0.effective_plan', 'pro');

        $this->assertSame('yearly', Workspace::sole()->billing_interval);
    }

    public function test_trial_reminder_is_sent_once(): void
    {
        Notification::fake();
        $this->signIn();
        $this->workspace->forceFill(['trial_ends_at' => now()->addDays(2)])->save();

        $this->artisan('crm:send-trial-reminders')->assertSuccessful();
        $this->artisan('crm:send-trial-reminders')->assertSuccessful();

        Notification::assertSentToTimes($this->user, TrialEndingSoon::class, 1);
    }

    public function test_public_plan_catalogue_hides_stripe_price_ids(): void
    {
        $this->getJson('/api/v1/plans')
            ->assertOk()
            ->assertJsonPath('checkout_enabled', true)
            ->assertJsonPath('data.1.prices.yearly', 29900)
            ->assertJsonMissingPath('data.1.stripe_prices');
    }
}
