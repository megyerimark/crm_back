<?php

namespace App\Services\Billing;

use App\Models\Workspace;
use Carbon\CarbonInterface;

/**
 * Stripe implementation on top of Laravel Cashier (the workspace is the billable model).
 */
class StripeBillingGateway implements BillingGateway
{
    public function checkoutUrl(Workspace $workspace, string $priceId, ?CarbonInterface $trialEndsAt, string $successUrl, string $cancelUrl): string
    {
        $workspace->createOrGetStripeCustomer();
        $this->syncCustomer($workspace);

        $builder = $workspace->newSubscription('default', $priceId)->withMetadata(['workspace_id' => (string) $workspace->id]);

        /* Subscribing during the free trial keeps the remaining trial days: the first charge happens when the trial ends. */
        if ($trialEndsAt !== null && $trialEndsAt->isAfter(now()->addHours(49))) {
            $builder->trialUntil($trialEndsAt);
        }

        $checkout = $builder->checkout([
            'success_url' => $successUrl,
            'cancel_url' => $cancelUrl,
            'locale' => in_array($workspace->locale, ['hu', 'en', 'de'], true) ? $workspace->locale : 'auto',
            'allow_promotion_codes' => true,
            'billing_address_collection' => 'auto',
        ]);

        return $checkout->asStripeCheckoutSession()->url;
    }

    public function portalUrl(Workspace $workspace, string $returnUrl): string
    {
        $workspace->createOrGetStripeCustomer();

        return $workspace->billingPortalUrl($returnUrl);
    }

    public function swap(Workspace $workspace, string $priceId): void
    {
        $workspace->subscription('default')->swap($priceId);
    }

    public function cancel(Workspace $workspace): void
    {
        $workspace->subscription('default')->cancel();
    }

    public function resume(Workspace $workspace): void
    {
        $workspace->subscription('default')->resume();
    }

    public function syncCustomer(Workspace $workspace): void
    {
        if ($workspace->hasStripeId()) {
            $workspace->syncStripeCustomerDetails();
        }
    }
}
