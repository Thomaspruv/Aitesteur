<?php

use App\Enums\AiProvider;
use App\Enums\RunStatus;
use App\Enums\Verdict;
use App\Enums\WorkflowStatus;
use App\Jobs\RunWorkflow;
use App\Models\Run;
use App\Models\User;
use App\Models\Workflow;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;

// A 1x1 transparent JPEG, just enough to exercise the decode/store path.
const TINY_JPEG_BASE64 = '/9j/4AAQSkZJRgABAQAAAQABAAD/2wBDAAMCAgICAgMCAgIDAwMDBAYEBAQEBAgGBgUGCQgKCgkICQkKDA8MCgsOCwkJDRENDg8QEBEQCgwSExIQEw8QEBD/2wBDAQMDAwQDBAgEBAgQCwkLEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBD/wAARCAABAAEDASIAAhEBAxEB/8QAFQABAQAAAAAAAAAAAAAAAAAAAAf/xAAUEAEAAAAAAAAAAAAAAAAAAAAA/8QAFQEBAQAAAAAAAAAAAAAAAAAAAAX/xAAUEQEAAAAAAAAAAAAAAAAAAAAA/9oADAMBAAIRAxEAPwCdABmX/9k=';

function fakeExecuteOutput(callable $resultFor): void
{
    Process::fake(function ($process) use ($resultFor) {
        $outputPath = collect($process->command)
            ->first(fn ($part) => str_starts_with($part, '--output='));
        $outputPath = substr($outputPath, strlen('--output='));

        file_put_contents($outputPath, json_encode($resultFor($process)));

        return Process::result();
    });
}

function projectWithAi(User $user)
{
    $project = $user->currentProject();
    $project->update(['ai_provider' => AiProvider::DeepSeek, 'ai_model' => 'deepseek-chat', 'ai_api_key' => 'sk-test']);
    $project->primaryEnvironment()->update(['url' => 'https://example.com']);

    return $project;
}

test('a successful run persists run steps and updates the run and workflow', function () {
    $user = User::factory()->create();
    $project = projectWithAi($user);

    $workflow = Workflow::factory()->create([
        'project_id' => $project->id,
        'status' => WorkflowStatus::Active,
        'steps' => [
            ['intent' => 'Ouvrir la page de connexion', 'assertions' => []],
            ['intent' => 'Se connecter', 'assertions' => []],
        ],
    ]);

    $run = $workflow->runs()->create(['status' => RunStatus::Queued, 'triggered_by' => 'manual']);

    fakeExecuteOutput(fn () => [
        'ok' => true,
        'steps' => [
            ['intent' => 'Ouvrir la page de connexion', 'state' => 'PASS', 'screenshot' => null, 'error' => null],
            ['intent' => 'Se connecter', 'state' => 'PASS', 'screenshot' => null, 'error' => null],
        ],
        'verdict' => 'PASS',
        'escalationLevel' => 0,
        'authenticated' => true,
        'error' => null,
    ]);

    (new RunWorkflow($run))->handle();

    $run->refresh();
    $workflow->refresh();

    expect($run->status)->toBe(RunStatus::Completed)
        ->and($run->verdict)->toBe(Verdict::Pass)
        ->and($run->escalation_level)->toBe(0)
        ->and($run->authenticated)->toBeTrue()
        ->and($run->started_at)->not->toBeNull()
        ->and($run->finished_at)->not->toBeNull()
        ->and($run->runSteps()->count())->toBe(2)
        ->and($workflow->latest_verdict)->toBe(Verdict::Pass)
        ->and($workflow->last_run_at)->not->toBeNull()
        ->and($workflow->spark_data)->not->toBeEmpty();
});

test('a passing candidate is marked verified', function () {
    $user = User::factory()->create();
    $project = projectWithAi($user);

    $workflow = Workflow::factory()->candidate()->create([
        'project_id' => $project->id,
        'verified' => false,
        'steps' => [['intent' => 'Ouvrir la page', 'assertions' => []]],
    ]);

    $run = $workflow->runs()->create(['status' => RunStatus::Queued, 'triggered_by' => 'manual']);

    fakeExecuteOutput(fn () => [
        'ok' => true,
        'steps' => [['intent' => 'Ouvrir la page', 'state' => 'PASS_HEALED', 'screenshot' => null, 'error' => null]],
        'verdict' => 'PASS_HEALED',
        'escalationLevel' => 1,
        'authenticated' => false,
        'error' => null,
    ]);

    (new RunWorkflow($run))->handle();

    expect($workflow->fresh()->verified)->toBeTrue();
});

test('a broken step stops the run and the remaining steps are recorded as skipped', function () {
    $user = User::factory()->create();
    $project = projectWithAi($user);

    $workflow = Workflow::factory()->create([
        'project_id' => $project->id,
        'verified' => false,
        'steps' => [
            ['intent' => 'Étape 1', 'assertions' => []],
            ['intent' => 'Étape 2', 'assertions' => []],
            ['intent' => 'Étape 3', 'assertions' => []],
        ],
    ]);

    $run = $workflow->runs()->create(['status' => RunStatus::Queued, 'triggered_by' => 'manual']);

    fakeExecuteOutput(fn () => [
        'ok' => true,
        'steps' => [
            ['intent' => 'Étape 1', 'state' => 'PASS', 'screenshot' => null, 'error' => null],
            ['intent' => 'Étape 2', 'state' => 'BROKEN', 'screenshot' => null, 'error' => 'action_failed: élément introuvable'],
            ['intent' => 'Étape 3', 'state' => 'SKIPPED', 'screenshot' => null, 'error' => null],
        ],
        'verdict' => 'BROKEN',
        'escalationLevel' => 1,
        'authenticated' => false,
        'error' => null,
    ]);

    (new RunWorkflow($run))->handle();

    $run->refresh();
    $states = $run->runSteps()->orderBy('position')->pluck('state');

    expect($run->verdict)->toBe(Verdict::Broken)
        ->and($run->diagnostic_summary)->toBe('action_failed: élément introuvable')
        ->and($states->all())->toBe([Verdict::Pass, Verdict::Broken, Verdict::Skipped])
        ->and($workflow->fresh()->verified)->toBeFalse();
});

test('a screenshot is decoded and stored on the public disk', function () {
    Storage::fake('public');

    $user = User::factory()->create();
    $project = projectWithAi($user);

    $workflow = Workflow::factory()->create([
        'project_id' => $project->id,
        'steps' => [['intent' => 'Étape 1', 'assertions' => []]],
    ]);

    $run = $workflow->runs()->create(['status' => RunStatus::Queued, 'triggered_by' => 'manual']);

    fakeExecuteOutput(fn () => [
        'ok' => true,
        'steps' => [
            ['intent' => 'Étape 1', 'state' => 'PASS', 'screenshot' => 'data:image/jpeg;base64,'.TINY_JPEG_BASE64, 'error' => null],
        ],
        'verdict' => 'PASS',
        'escalationLevel' => 0,
        'authenticated' => false,
        'error' => null,
    ]);

    (new RunWorkflow($run))->handle();

    $step = $run->runSteps()->first();

    expect($step->screenshot_path)->not->toBeNull();
    Storage::disk('public')->assertExists($step->screenshot_path);
});

test('a run fails cleanly when the project has no AI configured', function () {
    $user = User::factory()->create();
    $project = $user->currentProject();
    $project->primaryEnvironment()->update(['url' => 'https://example.com']);

    $workflow = Workflow::factory()->create(['project_id' => $project->id]);
    $run = $workflow->runs()->create(['status' => RunStatus::Queued, 'triggered_by' => 'manual']);

    (new RunWorkflow($run))->handle();

    expect($run->fresh()->status)->toBe(RunStatus::Failed)
        ->and($run->fresh()->error)->toContain('Aucun fournisseur IA configuré');
});

test('blank step intents are filtered out before dispatching, and a workflow left with none fails cleanly', function () {
    $user = User::factory()->create();
    $project = projectWithAi($user);

    $workflow = Workflow::factory()->create([
        'project_id' => $project->id,
        'steps' => [['intent' => '   ', 'assertions' => []]],
    ]);

    $run = $workflow->runs()->create(['status' => RunStatus::Queued, 'triggered_by' => 'manual']);

    Process::fake();

    (new RunWorkflow($run))->handle();

    expect($run->fresh()->status)->toBe(RunStatus::Failed)
        ->and($run->fresh()->error)->toContain('aucune étape avec une intention');

    Process::assertNothingRan();
});

test('a run fails cleanly when the environment has no url', function () {
    $user = User::factory()->create();
    $project = projectWithAi($user);
    $project->primaryEnvironment()->update(['url' => null]);

    $workflow = Workflow::factory()->create(['project_id' => $project->id]);
    $run = $workflow->runs()->create(['status' => RunStatus::Queued, 'triggered_by' => 'manual']);

    (new RunWorkflow($run))->handle();

    expect($run->fresh()->status)->toBe(RunStatus::Failed);
});

test('an interrupted run persists the steps that already completed instead of losing them', function () {
    $user = User::factory()->create();
    $project = projectWithAi($user);

    $workflow = Workflow::factory()->create([
        'project_id' => $project->id,
        'steps' => [
            ['intent' => 'Étape 1', 'assertions' => []],
            ['intent' => 'Étape 2', 'assertions' => []],
        ],
    ]);

    $run = $workflow->runs()->create(['status' => RunStatus::Queued, 'triggered_by' => 'manual']);

    Process::fake(function ($process) {
        $outputPath = collect($process->command)->first(fn ($part) => str_starts_with($part, '--output='));
        $outputPath = substr($outputPath, strlen('--output='));

        // Simulates execute.mjs's incremental writes: one step genuinely
        // completed and was written to disk before the process was killed
        // (e.g. a timeout) — verdict/escalationLevel never got set.
        file_put_contents($outputPath, json_encode([
            'ok' => true,
            'steps' => [
                ['intent' => 'Étape 1', 'state' => 'PASS', 'screenshot' => null, 'error' => null],
            ],
            'verdict' => null,
            'escalationLevel' => null,
            'authenticated' => false,
            'error' => null,
        ]));

        throw new RuntimeException('The process exceeded the timeout of 900 seconds.');
    });

    (new RunWorkflow($run))->handle();

    $run->refresh();

    expect($run->status)->toBe(RunStatus::Failed)
        ->and($run->verdict)->toBeNull()
        ->and($run->error)->toContain('interrompu')
        ->and($run->runSteps()->count())->toBe(1)
        ->and($run->runSteps()->first()->state)->toBe(Verdict::Pass);
});

test('missing execute output marks the run as failed', function () {
    $user = User::factory()->create();
    $project = projectWithAi($user);

    $workflow = Workflow::factory()->create(['project_id' => $project->id]);
    $run = $workflow->runs()->create(['status' => RunStatus::Queued, 'triggered_by' => 'manual']);

    Process::fake(fn () => Process::result(errorOutput: 'node: command not found'));

    (new RunWorkflow($run))->handle();

    expect($run->fresh()->status)->toBe(RunStatus::Failed);
});

test('the failed() hook marks an in-progress run failed as a safety net', function () {
    $user = User::factory()->create();
    $project = $user->currentProject();

    $workflow = Workflow::factory()->create(['project_id' => $project->id]);
    $run = Run::factory()->create([
        'workflow_id' => $workflow->id,
        'status' => RunStatus::Running,
        'verdict' => null,
    ]);

    (new RunWorkflow($run))->failed(new RuntimeException('Le worker a été tué.'));

    expect($run->fresh()->status)->toBe(RunStatus::Failed)
        ->and($run->fresh()->error)->toBe('Le worker a été tué.');
});
