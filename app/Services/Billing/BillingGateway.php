<?php

namespace App\Services\Billing;

use App\Models\Workspace;
use Carbon\CarbonInterface;

/**
 * Payment provider operations that talk to the provider's API (Stripe in production, a fake in tests).
 */
interface BillingGateway
{
    /**
     * Creates a hosted checkout session for a new subscription and returns its URL.
     */
    public function checkoutUrl(Workspace $workspace, string $priceId, ?CarbonInterface $trialEndsAt, string $successUrl, string $cancelUrl): string;

    /**
     * Returns a customer portal URL (payment method, cancellation, receipts).
     */
    public function portalUrl(Workspace $workspace, string $returnUrl): string;

    /**
     * Changes the price of the active subscription (with proration).
     */
    public function swap(Workspace $workspace, string $priceId): void;

    /**
     * Cancels at the end of the current billing period.
     */
    public function cancel(Workspace $workspace): void;

    public function resume(Workspace $workspace): void;

    /**
     * Cancels immediately (account deletion).
     */
    public function cancelNow(Workspace $workspace): void;

    /**
     * Pushes name, e-mail and address to the provider's customer record.
     */
    public function syncCustomer(Workspace $workspace): void;
}
