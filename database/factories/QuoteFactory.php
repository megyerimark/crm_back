<?php

namespace Database\Factories;

use App\Models\Quote;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Quote>
 */
class QuoteFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'workspace_id' => Workspace::factory(),
            'number' => 'AJ-'.now()->year.'-'.fake()->unique()->numerify('####'),
            'title' => fake()->catchPhrase(),
            'recipient_name' => fake()->name(),
            'recipient_email' => fake()->safeEmail(),
            'currency' => 'HUF',
            'valid_until' => now()->addDays(14)->toDateString(),
        ];
    }

    /**
     * A quote already sent to the customer, waiting for an answer.
     */
    public function sent(): static
    {
        return $this->state(fn () => [])->afterMaking(function (Quote $quote): void {
            $quote->forceFill([
                'status' => 'sent',
                'sent_at' => now(),
                'public_token' => Str::random(48),
                'follow_up_at' => now()->addDays(3),
            ]);
        });
    }
}
