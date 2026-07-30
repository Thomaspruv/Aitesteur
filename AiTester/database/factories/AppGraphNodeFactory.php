<?php

namespace Database\Factories;

use App\Models\AppGraphNode;
use App\Models\Project;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AppGraphNode>
 */
class AppGraphNodeFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'project_id' => Project::factory(),
            'key' => fake()->unique()->slug(2),
            'label' => fake()->words(2, true),
            'kind' => fake()->randomElement(['neutral', 'healthy', 'error', 'current']),
            'x' => fake()->numberBetween(20, 240),
            'y' => fake()->numberBetween(20, 160),
        ];
    }
}
