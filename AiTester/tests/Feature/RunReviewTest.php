<?php

use App\Enums\RunStatus;
use App\Enums\Verdict;
use App\Models\Run;
use App\Models\RunStep;
use App\Models\User;
use App\Models\Workflow;
use Livewire\Livewire;

test('run review renders the escalation ladder and a broken diagnostic', function () {
    $user = User::factory()->create();
    $project = $user->currentProject();

    $workflow = Workflow::factory()->create(['project_id' => $project->id, 'name' => 'Réinitialiser mot de passe']);
    $run = Run::factory()->broken()->create(['workflow_id' => $workflow->id]);
    RunStep::factory()->create(['run_id' => $run->id, 'position' => 0, 'intent' => 'Ouvrir la page de connexion', 'state' => Verdict::Pass]);
    RunStep::factory()->create(['run_id' => $run->id, 'position' => 1, 'intent' => 'Vérifier le formulaire', 'state' => Verdict::Broken]);

    $this->actingAs($user);

    Livewire::test('pages::app.runs.show', ['run' => $run])
        ->assertSee('Réinitialiser mot de passe')
        ->assertSee('BROKEN')
        ->assertSee('Ouvrir la page de connexion')
        ->assertSee($run->diagnostic_summary);
});

test('run review shows a clean panel when there was no escalation', function () {
    $user = User::factory()->create();
    $project = $user->currentProject();

    $workflow = Workflow::factory()->create(['project_id' => $project->id]);
    $run = Run::factory()->create(['workflow_id' => $workflow->id]);

    $this->actingAs($user);

    Livewire::test('pages::app.runs.show', ['run' => $run])
        ->assertSee('Aucune escalade nécessaire');
});

test('run review does not crash for a run with no verdict yet', function () {
    $user = User::factory()->create();
    $project = $user->currentProject();

    $workflow = Workflow::factory()->create(['project_id' => $project->id]);

    $this->actingAs($user);

    foreach ([RunStatus::Queued, RunStatus::Running, RunStatus::Failed] as $status) {
        $run = Run::factory()->create(['workflow_id' => $workflow->id, 'status' => $status, 'verdict' => null]);

        Livewire::test('pages::app.runs.show', ['run' => $run])
            ->assertOk();
    }
});

test('run review shows an in-progress banner and polls while a run is queued or running', function () {
    $user = User::factory()->create();
    $project = $user->currentProject();

    $workflow = Workflow::factory()->create(['project_id' => $project->id]);
    $run = Run::factory()->create(['workflow_id' => $workflow->id, 'status' => RunStatus::Running, 'verdict' => null]);

    $this->actingAs($user);

    Livewire::test('pages::app.runs.show', ['run' => $run])
        ->assertSee('En cours…')
        ->assertSeeHtml('wire:poll');
});

test('a user cannot view a run belonging to another organization', function () {
    $owner = User::factory()->create();
    $intruder = User::factory()->create();

    $workflow = Workflow::factory()->create(['project_id' => $owner->currentProject()->id]);
    $run = Run::factory()->create(['workflow_id' => $workflow->id]);

    $this->actingAs($intruder);

    $this->get(route('runs.show', $run))->assertForbidden();
});
