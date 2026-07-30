<?php

namespace Database\Factories;

use App\Models\Workflow;
use App\Models\WorkflowVersion;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WorkflowVersion>
 */
class WorkflowVersionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'workflow_id' => Workflow::factory(),
            'version' => fake()->unique()->numberBetween(1, 1000),
            'steps' => [
                ['intent' => fake()->sentence(), 'assertions' => [['label' => 'état atteint', 'on' => true]]],
            ],
            'change_label' => fake()->sentence(4),
        ];
    }
}
