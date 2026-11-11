<?php

namespace Database\Factories;

use App\Models\Deal;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Deal>
 */
class DealFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'workspace_id' => Workspace::factory(),
            'title' => fake()->catchPhrase(),
            'value' => fake()->numberBetween(10000, 900000),
            'currency' => 'HUF',
        ];
    }
}
