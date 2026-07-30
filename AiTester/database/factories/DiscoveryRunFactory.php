<?php

namespace Database\Factories;

use App\Enums\DiscoveryRunStatus;
use App\Models\DiscoveryRun;
use App\Models\Environment;
use App\Models\Project;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DiscoveryRun>
 */
class DiscoveryRunFactory extends Factory
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
            'environment_id' => Environment::factory(),
            'status' => DiscoveryRunStatus::Queued,
        ];
    }
}
