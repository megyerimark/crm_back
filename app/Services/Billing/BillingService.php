<?php

namespace App\Services\Billing;

use App\Enums\Plan;
use App\Models\Workspace;
use App\Services\AuditLogger;
use App\Services\PlanService;
use Laravel\Cashier\Subscription;

/**
 * Maps plans/intervals to Stripe prices and keeps the workspace plan in sync with its Stripe subscription.
 */
class BillingService
{
    public function __construct(protected AuditLogger $audit, protected PlanService $plans) {}

    public function isConfigured(): bool
    {
        return filled(config('cashier.secret')) && filled($this->priceId(Plan::Pro, 'monthly'));
    }

    public function priceId(Plan $plan, string $interval): ?string
    {
        return $this->plans->definition($plan)['stripe_prices'][$interval] ?? null;
    }

    /**
     * @return array{plan: Plan, interval: string}|null
     */
    public function planForPrice(?string $priceId): ?array
    {
        if (blank($priceId)) {
            return null;
        }

        foreach (Plan::cases() as $plan) {
            foreach (config('billing.intervals') as $interval) {
                if ($this->priceId($plan, $interval) === $priceId) {
                    return ['plan' => $plan, 'interval' => $interval];
                }
            }
        }

        return null;
    }

    public function subscription(Workspace $workspace): ?Subscription
    {
        return $workspace->subscriptions()->where('type', 'default')->first();
    }

    /**
     * Copies the state of the Stripe subscription onto the workspace so PlanService can resolve limits
     * without calling Stripe. Expiry or cancellation falls back to Free and never deletes data.
     */
    public function sync(Workspace $workspace): void
    {
        $subscription = $this->subscription($workspace->refresh());

        if (! $subscription) {
            return;
        }

        $before = $workspace->only(['plan', 'subscription_status', 'billing_interval']);
        $mapped = $this->planForPrice($subscription->stripe_price);

        if ($subscription->valid() && $mapped) {
            $workspace->forceFill([
                'plan' => $mapped['plan']->value,
                'billing_interval' => $mapped['interval'],
                'subscription_status' => 'active',
                'subscription_ends_at' => $subscription->ends_at,
                'intended_plan' => null,
            ]);
        } else {
            $workspace->forceFill([
                'subscription_status' => $subscription->pastDue() ? 'past_due' : 'canceled',
                'subscription_ends_at' => $subscription->ends_at ?? now(),
            ]);
        }

        $workspace->save();

        if ($before !== $workspace->only(['plan', 'subscription_status', 'billing_interval'])) {
            $this->audit->log('subscription.synced', $workspace, [
                'before' => $before,
                'after' => $workspace->only(['plan', 'subscription_status', 'billing_interval']),
                'stripe_status' => $subscription->stripe_status,
            ], $workspace->id);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function status(Workspace $workspace): array
    {
        $subscription = $this->subscription($workspace);

        return [
            'configured' => $this->isConfigured(),
            'has_subscription' => $subscription?->valid() ?? false,
            'plan' => $workspace->plan,
            'interval' => $workspace->billing_interval,
            'intended_plan' => $workspace->intended_plan,
            'stripe_status' => $subscription?->stripe_status,
            'on_grace_period' => $subscription?->onGracePeriod() ?? false,
            'ends_at' => $subscription?->ends_at,
            'subscription_trial_ends_at' => $subscription?->trial_ends_at,
            'past_due' => $subscription?->pastDue() ?? false,
            'payment_method' => $workspace->pm_type ? ['type' => $workspace->pm_type, 'last_four' => $workspace->pm_last_four] : null,
            'billing_details_complete' => $workspace->hasCompleteBillingDetails(),
        ];
    }
}
