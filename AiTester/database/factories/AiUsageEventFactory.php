<?php

namespace Database\Factories;

use App\Enums\AiProvider;
use App\Enums\AiUsageFeature;
use App\Models\AiUsageEvent;
use App\Models\Project;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AiUsageEvent>
 */
class AiUsageEventFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $promptTokens = $this->faker->numberBetween(200, 2000);
        $completionTokens = $this->faker->numberBetween(20, 300);

        return [
            'project_id' => Project::factory(),
            'feature' => AiUsageFeature::RunStep,
            'provider' => AiProvider::DeepSeek,
            'model' => 'deepseek-chat',
            'prompt_tokens' => $promptTokens,
            'completion_tokens' => $completionTokens,
            'total_tokens' => $promptTokens + $completionTokens,
            'cost_usd' => round(($promptTokens / 1_000_000 * 0.27) + ($completionTokens / 1_000_000 * 1.10), 6),
        ];
    }
}
