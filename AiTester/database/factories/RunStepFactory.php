<?php

namespace Database\Factories;

use App\Enums\Verdict;
use App\Models\Run;
use App\Models\RunStep;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RunStep>
 */
class RunStepFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'run_id' => Run::factory(),
            'position' => fake()->unique()->numberBetween(0, 9999),
            'intent' => fake()->sentence(),
            'state' => Verdict::Pass,
        ];
    }
}
