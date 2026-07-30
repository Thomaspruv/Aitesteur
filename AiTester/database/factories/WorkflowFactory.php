<?php

namespace Database\Factories;

use App\Enums\Criticality;
use App\Enums\Verdict;
use App\Enums\WorkflowOrigin;
use App\Enums\WorkflowStatus;
use App\Models\Project;
use App\Models\Workflow;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Workflow>
 */
class WorkflowFactory extends Factory
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
            'name' => fake()->sentence(3),
            'criticality' => fake()->randomElement(Criticality::cases()),
            'origin' => WorkflowOrigin::Discovered,
            'status' => WorkflowStatus::Active,
            'score' => fake()->numberBetween(50, 99),
            'verified' => true,
            'canary' => false,
            'latest_verdict' => Verdict::Pass,
            'last_run_at' => fake()->dateTimeBetween('-1 week'),
            'steps' => [
                ['intent' => fake()->sentence(), 'assertions' => [['label' => 'état atteint', 'on' => true]]],
            ],
            'spark_data' => fake()->randomElements(range(5, 16), 9),
        ];
    }

    public function candidate(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => WorkflowStatus::Candidate,
            'latest_verdict' => null,
            'last_run_at' => null,
            'verified' => fake()->boolean(70),
        ]);
    }
}
