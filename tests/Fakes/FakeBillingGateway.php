<?php

namespace Tests\Fakes;

use App\Models\Workspace;
use App\Services\Billing\BillingGateway;
use Carbon\CarbonInterface;

/**
 * In-memory stand-in for Stripe: records calls and mutates the local Cashier subscription
 * the way Cashier would after a successful API call.
 */
class FakeBillingGateway implements BillingGateway
{
    /**
     * @var list<array<string, mixed>>
     */
    public array $calls = [];

    public function checkoutUrl(Workspace $workspace, string $priceId, ?CarbonInterface $trialEndsAt, string $successUrl, string $cancelUrl): string
    {
        $this->calls[] = ['method' => 'checkout', 'price' => $priceId, 'trial_ends_at' => $trialEndsAt, 'success_url' => $successUrl];

        return 'https://checkout.stripe.test/session/'.$workspace->id;
    }

    public function portalUrl(Workspace $workspace, string $returnUrl): string
    {
        $this->calls[] = ['method' => 'portal', 'return_url' => $returnUrl];

        return 'https://billing.stripe.test/portal/'.$workspace->id;
    }

    public function swap(Workspace $workspace, string $priceId): void
    {
        $this->calls[] = ['method' => 'swap', 'price' => $priceId];
        $workspace->subscription('default')->forceFill(['stripe_price' => $priceId])->save();
    }

    public function cancel(Workspace $workspace): void
    {
        $this->calls[] = ['method' => 'cancel'];
        $workspace->subscription('default')->forceFill(['ends_at' => now()->addDays(20)])->save();
    }

    public function resume(Workspace $workspace): void
    {
        $this->calls[] = ['method' => 'resume'];
        $workspace->subscription('default')->forceFill(['ends_at' => null])->save();
    }

    public function cancelNow(Workspace $workspace): void
    {
        $this->calls[] = ['method' => 'cancelNow'];
        $workspace->subscription('default')->forceFill(['stripe_status' => 'canceled', 'ends_at' => now()])->save();
    }

    public function syncCustomer(Workspace $workspace): void
    {
        $this->calls[] = ['method' => 'syncCustomer'];
    }
}
