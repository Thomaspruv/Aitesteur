<?php

use App\Livewire\ProjectSwitcher;
use App\Models\Project;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Livewire\Livewire;

test('switching project persists in session and is picked up by a fresh currentProject() call', function () {
    $user = User::factory()->create();
    $originalProject = $user->currentProject();
    $otherProject = Project::factory()->create(['organization_id' => $originalProject->organization_id]);

    $this->actingAs($user);

    Livewire::test(ProjectSwitcher::class)->call('activate', $otherProject->id);

    expect(session('active_project_id'))->toBe($otherProject->id)
        // currentProject() is once()-memoized per instance, so a fresh User
        // object is what proves the session pick — not just the cached one.
        ->and($user->fresh()->currentProject()->id)->toBe($otherProject->id);
});

test('switching to a project the user does not own is rejected', function () {
    $user = User::factory()->create();
    $stranger = User::factory()->create();
    $foreignProject = $stranger->currentProject();

    $this->actingAs($user);

    expect(fn () => Livewire::test(ProjectSwitcher::class)->call('activate', $foreignProject->id))
        ->toThrow(ModelNotFoundException::class);

    expect(session('active_project_id'))->toBeNull();
});

test('creating a project makes it active with a unique slug under the user org', function () {
    $user = User::factory()->create();
    $organization = $user->organizations()->first();

    $this->actingAs($user);

    Livewire::test(ProjectSwitcher::class)
        ->set('newProjectName', 'Mon projet')
        ->call('create');

    // The auto-provisioned project is also named "Mon projet" — ordering by
    // id picks the one just created, not the pre-existing one.
    $created = $organization->projects()->where('name', 'Mon projet')->latest('id')->first();

    expect($created)->not->toBeNull()
        // The auto-provisioned project is already named "Mon projet" with
        // slug "mon-projet" (UserObserver) — this proves the dedupe suffix
        // actually kicks in rather than colliding silently.
        ->and($created->slug)->not->toBe('mon-projet')
        ->and($user->fresh()->currentProject()->id)->toBe($created->id);
});

test('creating a project requires a name', function () {
    $user = User::factory()->create();

    $this->actingAs($user);

    Livewire::test(ProjectSwitcher::class)
        ->set('newProjectName', '')
        ->call('create')
        ->assertHasErrors('newProjectName');
});
