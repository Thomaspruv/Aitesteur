<?php

use App\Enums\Verdict;
use App\Models\Run;
use App\Models\User;
use App\Models\Workflow;
use Livewire\Livewire;

test('workflow run history lists runs newest first', function () {
    $user = User::factory()->create();
    $project = $user->currentProject();

    $workflow = Workflow::factory()->create(['project_id' => $project->id]);

    $older = Run::factory()->create(['workflow_id' => $workflow->id, 'verdict' => Verdict::Pass, 'created_at' => now()->subDay()]);
    $newer = Run::factory()->create(['workflow_id' => $workflow->id, 'verdict' => Verdict::Broken, 'created_at' => now()]);

    $this->actingAs($user);

    $component = Livewire::test('pages::app.workflows.runs', ['workflow' => $workflow])
        ->assertSee('run #'.$older->id)
        ->assertSee('run #'.$newer->id);

    $ids = $component->instance()->runs->pluck('id')->all();

    expect($ids)->toBe([$newer->id, $older->id]);
});

test('workflow run history paginates', function () {
    $user = User::factory()->create();
    $project = $user->currentProject();

    $workflow = Workflow::factory()->create(['project_id' => $project->id]);
    Run::factory()->count(25)->create(['workflow_id' => $workflow->id]);

    $this->actingAs($user);

    $component = Livewire::test('pages::app.workflows.runs', ['workflow' => $workflow]);

    expect($component->instance()->runs->total())->toBe(25)
        ->and($component->instance()->runs->count())->toBe(20);
});

test('a user cannot view run history for a workflow belonging to another organization', function () {
    $owner = User::factory()->create();
    $intruder = User::factory()->create();

    $workflow = Workflow::factory()->create(['project_id' => $owner->currentProject()->id]);

    $this->actingAs($intruder);

    $this->get(route('workflows.runs', $workflow))->assertForbidden();
});
