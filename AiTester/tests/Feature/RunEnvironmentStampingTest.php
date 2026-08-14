<?php

use App\Enums\AiProvider;
use App\Enums\RunStatus;
use App\Enums\Verdict;
use App\Jobs\RunWorkflow;
use App\Models\Environment;
use App\Models\User;
use App\Models\Workflow;
use App\Support\UrlHost;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Session;
use Livewire\Livewire;

test('launching a run stamps the environment that is currently active, not just the project\'s first one', function () {
    $user = User::factory()->create();
    $project = $user->currentProject();
    $project->update(['ai_provider' => AiProvider::DeepSeek, 'ai_model' => 'deepseek-chat', 'ai_api_key' => 'sk-test']);

    $secondEnvironment = Environment::factory()->create(['project_id' => $project->id, 'name' => 'production']);
    Session::put('active_environment_id', $secondEnvironment->id);

    $workflow = Workflow::factory()->create(['project_id' => $project->id]);

    $this->actingAs($user);

    Livewire::test('pages::app.workflows.edit', ['workflow' => $workflow])->call('launchRun');

    expect($workflow->runs()->first()->environment_id)->toBe($secondEnvironment->id);
});

test('a queued run executes against its stamped environment even if the project\'s active environment changes afterwards', function () {
    $user = User::factory()->create();
    $project = $user->currentProject();
    $project->update(['ai_provider' => AiProvider::DeepSeek, 'ai_model' => 'deepseek-chat', 'ai_api_key' => 'sk-test']);

    // The factory derives target_authorized_host from its own generated url —
    // overriding just 'url' here without also overriding the host would leave
    // the two mismatched and fail RunWorkflow's authorization check before it
    // ever gets to the executor, so both environments use their factory url.
    $targetEnvironment = Environment::factory()->create(['project_id' => $project->id, 'name' => 'target']);
    // A second environment that becomes "active" after the run was created —
    // proves RunWorkflow uses the run's own snapshot, not whatever is active
    // by the time the queue worker picks the job up.
    Environment::factory()->create(['project_id' => $project->id, 'name' => 'decoy']);

    $workflow = Workflow::factory()->create(['project_id' => $project->id]);
    $run = $workflow->runs()->create([
        'status' => RunStatus::Queued,
        'triggered_by' => 'manual',
        'environment_id' => $targetEnvironment->id,
    ]);

    Process::fake(function ($process) {
        $outputPath = collect($process->command)->first(fn ($part) => str_starts_with($part, '--output='));
        $outputPath = substr($outputPath, strlen('--output='));

        file_put_contents($outputPath, json_encode([
            'ok' => true,
            'steps' => [],
            'verdict' => 'PASS',
            'escalationLevel' => 0,
            'authenticated' => false,
            'error' => null,
        ]));

        return Process::result();
    });

    (new RunWorkflow($run))->handle();

    expect($run->fresh()->verdict)->toBe(Verdict::Pass);

    Process::assertRan(fn ($process) => in_array('--url='.UrlHost::normalize($targetEnvironment->url), $process->command, true));
});
