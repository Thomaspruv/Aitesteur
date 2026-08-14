<?php

use App\Livewire\EnvironmentSwitcher;
use App\Models\Environment;
use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Facades\Session;
use Livewire\Livewire;

test('switching environment persists in session and is picked up by activeEnvironment()', function () {
    $user = User::factory()->create();
    $project = $user->currentProject();
    $secondEnvironment = Environment::factory()->create(['project_id' => $project->id, 'name' => 'production']);

    $this->actingAs($user);

    Livewire::test(EnvironmentSwitcher::class)->call('activate', $secondEnvironment->id);

    expect(session('active_environment_id'))->toBe($secondEnvironment->id)
        ->and($project->activeEnvironment()->id)->toBe($secondEnvironment->id);
});

test('switching to an environment belonging to another project is rejected', function () {
    $user = User::factory()->create();
    $foreignEnvironment = Environment::factory()->create(['project_id' => Project::factory()->create()->id]);

    $this->actingAs($user);

    // abort_unless()'s HttpException is rendered by Livewire's own test request
    // cycle (not bubbled to the test — Livewire's RequestBroker explicitly
    // excludes HttpException from "without exception handling") so the
    // observable proof here is that the session was never mutated.
    Livewire::test(EnvironmentSwitcher::class)->call('activate', $foreignEnvironment->id);

    expect(session('active_environment_id'))->toBeNull();
});

test('activeEnvironment() self-heals when the session pick belongs to a different project', function () {
    $user = User::factory()->create();
    $project = $user->currentProject();
    $otherProject = Project::factory()->create(['organization_id' => $project->organization_id]);
    $otherEnvironment = Environment::factory()->create(['project_id' => $otherProject->id]);

    Session::put('active_environment_id', $otherEnvironment->id);

    // Stale pick from a different project is ignored, falling back to this
    // project's own primary environment rather than leaking the other one.
    expect($project->activeEnvironment()->id)->toBe($project->primaryEnvironment()->id)
        ->and($project->activeEnvironment()->id)->not->toBe($otherEnvironment->id);
});
