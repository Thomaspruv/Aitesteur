<?php

namespace Database\Factories;

use App\Models\Environment;
use App\Models\Project;
use App\Support\UrlHost;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Environment>
 */
class EnvironmentFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $url = 'staging.'.fake()->domainName();

        return [
            'project_id' => Project::factory(),
            'name' => 'staging',
            'url' => $url,
            // Tests build environments to immediately run discoveries/workflows
            // against, not to exercise the authorization gate itself — that gate
            // has its own dedicated tests, which override this back to null.
            'target_authorized_at' => now(),
            'target_authorized_host' => UrlHost::of($url),
        ];
    }
}
