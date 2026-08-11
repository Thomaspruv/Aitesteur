<?php

namespace App\Services\Ai;

use App\Enums\AiUsageFeature;
use App\Models\AiUsageEvent;
use App\Models\DiscoveryRun;
use App\Models\Project;
use App\Models\Run;
use App\Support\AiUsage;
use Illuminate\Support\Facades\Log;

class AiUsageRecorder
{
    public static function record(
        Project $project,
        AiUsageFeature $feature,
        AiUsage $usage,
        ?Run $run = null,
        ?DiscoveryRun $discoveryRun = null,
    ): AiUsageEvent {
        $rates = self::rates($project, $usage->model);

        $costUsd = ($usage->promptTokens / 1_000_000 * $rates['input_per_million'])
            + ($usage->completionTokens / 1_000_000 * $rates['output_per_million']);

        return AiUsageEvent::create([
            'project_id' => $project->id,
            'run_id' => $run?->id,
            'discovery_run_id' => $discoveryRun?->id,
            'feature' => $feature,
            'provider' => $project->ai_provider,
            'model' => $usage->model,
            'prompt_tokens' => $usage->promptTokens,
            'completion_tokens' => $usage->completionTokens,
            'total_tokens' => $usage->totalTokens(),
            'cost_usd' => round($costUsd, 6),
        ]);
    }

    /**
     * @return array{input_per_million: float, output_per_million: float}
     */
    protected static function rates(Project $project, string $model): array
    {
        $provider = $project->ai_provider?->value;
        $rates = config("ai_pricing.{$provider}.{$model}");

        if (! self::isRatePair($rates)) {
            Log::warning('No configured AI pricing rate for model, falling back to provider default.', [
                'provider' => $provider,
                'model' => $model,
            ]);

            $rates = config("ai_pricing.{$provider}.default");
        }

        return self::isRatePair($rates) ? $rates : ['input_per_million' => 0.0, 'output_per_million' => 0.0];
    }

    /**
     * @phpstan-assert-if-true array{input_per_million: float, output_per_million: float} $rates
     */
    protected static function isRatePair(mixed $rates): bool
    {
        return is_array($rates) && is_float($rates['input_per_million'] ?? null) && is_float($rates['output_per_million'] ?? null);
    }
}
