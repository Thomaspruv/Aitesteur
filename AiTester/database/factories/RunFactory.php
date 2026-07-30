<?php

namespace Database\Factories;

use App\Enums\RunStatus;
use App\Enums\Verdict;
use App\Models\Run;
use App\Models\Workflow;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Run>
 */
class RunFactory extends Factory
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
            'status' => RunStatus::Completed,
            'verdict' => Verdict::Pass,
            'escalation_level' => 0,
            'triggered_by' => 'deploy',
            'confirmed' => false,
        ];
    }

    public function broken(): static
    {
        return $this->state(fn (array $attributes) => [
            'verdict' => Verdict::Broken,
            'escalation_level' => 3,
            'confirmed' => true,
            'expected_label' => 'baseline',
            'observed_label' => 'erreur 500',
            'diagnostic_summary' => 'La page affiche une erreur serveur — non réalisable autrement → régression confirmée.',
        ]);
    }
}
