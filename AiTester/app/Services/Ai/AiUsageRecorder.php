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

        // Looked up via an explicit path array, not a dot-notation string
        // ("ai_pricing.{$provider}.{$model}") — a model name containing a
        // dot (e.g. a future "gpt-3.5-turbo") would otherwise be parsed as
        // nested config segments instead of matching the intended flat key.
        $rates = data_get(config('ai_pricing'), [$provider, $model]);

        if (! self::isRatePair($rates)) {
            Log::warning('No configured AI pricing rate for model, falling back to provider default.', [
                'provider' => $provider,
                'model' => $model,
            ]);

            $rates = data_get(config('ai_pricing'), [$provider, 'default']);
        }

        if (! self::isRatePair($rates)) {
            return ['input_per_million' => 0.0, 'output_per_million' => 0.0];
        }

        return [
            'input_per_million' => (float) $rates['input_per_million'],
            'output_per_million' => (float) $rates['output_per_million'],
        ];
    }

    /**
     * is_numeric() rather than is_float() — a config rate written as a whole
     * number (e.g. `1` instead of `1.0`) is a valid int in PHP and would
     * otherwise be silently rejected as "not configured."
     *
     * @phpstan-assert-if-true array{input_per_million: int|float, output_per_million: int|float} $rates
     */
    protected static function isRatePair(mixed $rates): bool
    {
        return is_array($rates) && is_numeric($rates['input_per_million'] ?? null) && is_numeric($rates['output_per_million'] ?? null);
    }
}
