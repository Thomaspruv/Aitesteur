<?php

use App\Enums\AiProvider;
use App\Enums\AiUsageFeature;
use App\Enums\RunStatus;
use App\Models\AiUsageEvent;
use App\Models\User;
use App\Models\Workflow;
use App\Services\Ai\AiUsageRecorder;
use App\Support\AiUsage;

function projectWithDeepSeek(?string $model = 'deepseek-chat')
{
    $project = User::factory()->create()->currentProject();
    $project->update(['ai_provider' => AiProvider::DeepSeek, 'ai_model' => $model, 'ai_api_key' => 'sk-test']);

    return $project;
}

test('it computes cost from the configured per-model rate and persists an event', function () {
    config(['ai_pricing.deepseek.deepseek-chat' => ['input_per_million' => 1.0, 'output_per_million' => 2.0]]);

    $project = projectWithDeepSeek();

    $event = AiUsageRecorder::record(
        project: $project,
        feature: AiUsageFeature::RunStep,
        usage: new AiUsage(promptTokens: 1_000_000, completionTokens: 500_000, model: 'deepseek-chat'),
    );

    expect($event)->toBeInstanceOf(AiUsageEvent::class)
        ->and($event->project_id)->toBe($project->id)
        ->and($event->run_id)->toBeNull()
        ->and($event->discovery_run_id)->toBeNull()
        ->and($event->feature)->toBe(AiUsageFeature::RunStep)
        ->and($event->provider)->toBe(AiProvider::DeepSeek)
        ->and($event->prompt_tokens)->toBe(1_000_000)
        ->and($event->completion_tokens)->toBe(500_000)
        ->and($event->total_tokens)->toBe(1_500_000)
        ->and((float) $event->cost_usd)->toBe(2.0);
});

test('an unknown model falls back to the provider default rate', function () {
    config([
        'ai_pricing.deepseek' => [
            'default' => ['input_per_million' => 5.0, 'output_per_million' => 10.0],
        ],
    ]);

    $project = projectWithDeepSeek('deepseek-experimental');

    $event = AiUsageRecorder::record(
        project: $project,
        feature: AiUsageFeature::DiscoveryAnnotation,
        usage: new AiUsage(promptTokens: 1_000_000, completionTokens: 0, model: 'deepseek-experimental'),
    );

    expect((float) $event->cost_usd)->toBe(5.0);
});

test('a run and discovery run can both be attributed on the same event only via their own field', function () {
    $project = projectWithDeepSeek();
    $run = Workflow::factory()->create(['project_id' => $project->id])->runs()->create(['status' => RunStatus::Queued, 'triggered_by' => 'manual']);

    $event = AiUsageRecorder::record(
        project: $project,
        feature: AiUsageFeature::RunStep,
        usage: new AiUsage(promptTokens: 100, completionTokens: 50, model: 'deepseek-chat'),
        run: $run,
    );

    expect($event->run_id)->toBe($run->id)
        ->and($event->discovery_run_id)->toBeNull();
});
