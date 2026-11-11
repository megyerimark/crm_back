<?php

namespace Database\Factories;

use App\Models\Form;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Form>
 */
class FormFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'workspace_id' => Workspace::factory(),
            'name' => 'Ajánlatkérés',
            'slug' => fake()->unique()->slug(2),
            'status' => 'published',
            'locale' => 'hu',
            'fields' => Form::defaultFields(),
            'notify_owner' => true,
            'show_branding' => true,
        ];
    }
}
