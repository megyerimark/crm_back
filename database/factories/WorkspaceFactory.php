<?php

namespace Database\Factories;

use App\Models\User;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Workspace>
 */
class WorkspaceFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->company(),
            'slug' => fake()->unique()->slug(3),
            'owner_id' => User::factory(),
            'country' => 'HU',
            'timezone' => 'Europe/Budapest',
            'currency' => 'HUF',
            'locale' => 'hu',
            'plan' => 'free',
            'trial_ends_at' => null,
        ];
    }

    public function onTrial(): static
    {
        return $this->state(fn () => ['trial_ends_at' => now()->addDays(14)]);
    }

    public function subscribed(string $plan): static
    {
        return $this->state(fn () => ['plan' => $plan, 'subscription_status' => 'active', 'trial_ends_at' => null]);
    }
}
