<?php

use App\Enums\AiProvider;
use App\Enums\RunStatus;
use App\Enums\WorkflowStatus;
use App\Jobs\RunWorkflow;
use App\Models\User;
use App\Models\Workflow;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

test('workflow editor can toggle an assertion and records a new version', function () {
    $user = User::factory()->create();
    $project = $user->currentProject();

    $workflow = Workflow::factory()->create([
        'project_id' => $project->id,
        'steps' => [
            ['intent' => 'Se connecter', 'assertions' => [['label' => 'état atteint', 'on' => true]]],
        ],
    ]);

    $this->actingAs($user);

    Livewire::test('pages::app.workflows.edit', ['workflow' => $workflow])
        ->assertSeeHtml('Se connecter')
        ->call('toggleAssertion', 0, 0)
        ->assertSet('steps.0.assertions.0.on', false);

    expect($workflow->fresh()->steps[0]['assertions'][0]['on'])->toBeFalse()
        ->and($workflow->workflowVersions()->count())->toBe(1);
});

test('workflow editor can edit a step intent', function () {
    $user = User::factory()->create();
    $project = $user->currentProject();

    $workflow = Workflow::factory()->create([
        'project_id' => $project->id,
        'steps' => [['intent' => 'Ancienne intention', 'assertions' => []]],
    ]);

    $this->actingAs($user);

    Livewire::test('pages::app.workflows.edit', ['workflow' => $workflow])
        ->set('steps.0.intent', 'Nouvelle intention')
        ->call('saveIntent', 0);

    expect($workflow->fresh()->steps[0]['intent'])->toBe('Nouvelle intention');
});

test('workflow editor rejects a blank step intent instead of saving it', function () {
    $user = User::factory()->create();
    $project = $user->currentProject();

    $workflow = Workflow::factory()->create([
        'project_id' => $project->id,
        'steps' => [['intent' => 'Intention existante', 'assertions' => []]],
    ]);

    $this->actingAs($user);

    Livewire::test('pages::app.workflows.edit', ['workflow' => $workflow])
        ->set('steps.0.intent', '   ')
        ->call('saveIntent', 0)
        ->assertSet('steps.0.intent', 'Intention existante');

    expect($workflow->fresh()->steps[0]['intent'])->toBe('Intention existante')
        ->and($workflow->fresh()->workflowVersions()->count())->toBe(0);
});

test('workflow editor can activate a candidate directly', function () {
    $user = User::factory()->create();
    $project = $user->currentProject();

    $workflow = Workflow::factory()->candidate()->create(['project_id' => $project->id]);

    $this->actingAs($user);

    Livewire::test('pages::app.workflows.edit', ['workflow' => $workflow])
        ->call('activate');

    expect($workflow->fresh()->status)->toBe(WorkflowStatus::Active);
});

test('workflow editor can deactivate an active workflow', function () {
    $user = User::factory()->create();
    $project = $user->currentProject();

    $workflow = Workflow::factory()->create(['project_id' => $project->id, 'status' => WorkflowStatus::Active]);

    $this->actingAs($user);

    Livewire::test('pages::app.workflows.edit', ['workflow' => $workflow])
        ->call('ignore');

    expect($workflow->fresh()->status)->toBe(WorkflowStatus::Ignored);
});

test('launching a run dispatches RunWorkflow when AI is configured', function () {
    Queue::fake();

    $user = User::factory()->create();
    $project = $user->currentProject();
    $project->update(['ai_provider' => AiProvider::DeepSeek, 'ai_model' => 'deepseek-chat', 'ai_api_key' => 'sk-test']);

    $workflow = Workflow::factory()->create(['project_id' => $project->id]);

    $this->actingAs($user);

    Livewire::test('pages::app.workflows.edit', ['workflow' => $workflow])
        ->call('launchRun');

    Queue::assertPushed(RunWorkflow::class, fn ($job) => $job->run->workflow_id === $workflow->id);
    expect($workflow->runs()->first()->status)->toBe(RunStatus::Queued);
});

test('launching a run is blocked when no AI is configured', function () {
    Queue::fake();

    $user = User::factory()->create();
    $workflow = Workflow::factory()->create(['project_id' => $user->currentProject()->id]);

    $this->actingAs($user);

    Livewire::test('pages::app.workflows.edit', ['workflow' => $workflow])
        ->call('launchRun');

    Queue::assertNotPushed(RunWorkflow::class);
    expect($workflow->runs()->count())->toBe(0);
});

test('launching a run while one is already in progress is blocked', function () {
    Queue::fake();

    $user = User::factory()->create();
    $project = $user->currentProject();
    $project->update(['ai_provider' => AiProvider::DeepSeek, 'ai_model' => 'deepseek-chat', 'ai_api_key' => 'sk-test']);

    $workflow = Workflow::factory()->create(['project_id' => $project->id]);
    $workflow->runs()->create(['status' => RunStatus::Running, 'triggered_by' => 'manual']);

    $this->actingAs($user);

    Livewire::test('pages::app.workflows.edit', ['workflow' => $workflow])
        ->call('launchRun');

    Queue::assertNotPushed(RunWorkflow::class);
    expect($workflow->runs()->count())->toBe(1);
});

test('a user cannot edit a workflow belonging to another organization', function () {
    $owner = User::factory()->create();
    $intruder = User::factory()->create();

    $workflow = Workflow::factory()->create(['project_id' => $owner->currentProject()->id]);

    $this->actingAs($intruder);

    $this->get(route('workflows.edit', $workflow))->assertForbidden();
});
