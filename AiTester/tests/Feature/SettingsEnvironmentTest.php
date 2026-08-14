<?php

use App\Models\User;
use Livewire\Livewire;

test('it saves the url and credentials', function () {
    $user = User::factory()->create();
    $project = $user->currentProject();

    $this->actingAs($user);

    Livewire::test('pages::app.settings-environment')
        ->set('url', 'https://staging.example.com')
        ->set('username', 'demo')
        ->set('password', 'secret')
        ->set('targetAuthorized', true)
        ->call('save');

    $environment = $project->primaryEnvironment()->refresh();

    expect($environment->url)->toBe('https://staging.example.com')
        ->and($environment->username)->toBe('demo')
        ->and($environment->password)->toBe('secret')
        ->and($environment->target_authorized_at)->not->toBeNull();
});

test('saving a new target without confirming authorization is blocked', function () {
    $user = User::factory()->create();
    $project = $user->currentProject();

    $this->actingAs($user);

    Livewire::test('pages::app.settings-environment')
        ->set('url', 'https://staging.example.com')
        ->call('save')
        ->assertHasErrors('targetAuthorized');

    expect($project->primaryEnvironment()->refresh()->url)->toBeNull();
});

test('resubmitting with a blank password does not wipe the existing one', function () {
    $user = User::factory()->create();
    $project = $user->currentProject();
    $project->primaryEnvironment()->update([
        'url' => 'https://old.example.com',
        'username' => 'demo',
        'password' => 'sk-existing',
        'target_authorized_at' => now(),
        'target_authorized_host' => 'old.example.com',
    ]);

    $this->actingAs($user);

    Livewire::test('pages::app.settings-environment')
        ->set('url', 'https://new.example.com')
        ->set('targetAuthorized', true)
        ->call('save');

    $environment = $project->primaryEnvironment()->refresh();

    expect($environment->url)->toBe('https://new.example.com')
        ->and($environment->username)->toBe('demo')
        ->and($environment->password)->toBe('sk-existing');
});

test('resaving the same host does not require re-confirming authorization', function () {
    $user = User::factory()->create();
    $project = $user->currentProject();
    $originalAuthorizedAt = now()->subDay();
    $project->primaryEnvironment()->update([
        'url' => 'https://staging.example.com',
        'target_authorized_at' => $originalAuthorizedAt,
        'target_authorized_host' => 'staging.example.com',
    ]);

    $this->actingAs($user);

    Livewire::test('pages::app.settings-environment')
        ->set('username', 'demo')
        ->call('save')
        ->assertHasNoErrors();

    $environment = $project->primaryEnvironment()->refresh();

    // Not just "no error" — the stored authorization must be genuinely left
    // alone (not silently refreshed to now()), otherwise a regression that
    // always re-stamps on save would pass this test undetected.
    expect($environment->username)->toBe('demo')
        ->and($environment->target_authorized_at->timestamp)->toBe($originalAuthorizedAt->timestamp)
        ->and($environment->target_authorized_host)->toBe('staging.example.com');
});

test('clearing an already-authorized url does not require re-confirming authorization', function () {
    $user = User::factory()->create();
    $project = $user->currentProject();
    $project->primaryEnvironment()->update([
        'url' => 'https://staging.example.com',
        'target_authorized_at' => now(),
        'target_authorized_host' => 'staging.example.com',
    ]);

    $this->actingAs($user);

    Livewire::test('pages::app.settings-environment')
        ->set('url', '')
        ->call('save')
        ->assertHasNoErrors();

    expect($project->primaryEnvironment()->refresh()->url)->toBeNull();
});

test('it creates a primary environment on the fly if none exists yet', function () {
    $user = User::factory()->create();
    $project = $user->currentProject();
    $project->environments()->delete();

    $this->actingAs($user);

    Livewire::test('pages::app.settings-environment')
        ->set('url', 'https://example.com')
        ->set('targetAuthorized', true)
        ->call('save');

    expect($project->primaryEnvironment())->not->toBeNull()
        ->and($project->primaryEnvironment()->url)->toBe('https://example.com');
});

test('a free-plan project is blocked from creating a second environment', function () {
    $user = User::factory()->create();
    $project = $user->currentProject();

    $this->actingAs($user);

    Livewire::test('pages::app.settings-environment')
        ->call('createEnvironment');

    expect($project->environments()->count())->toBe(1);
});

test('a pro-plan project can create additional environments', function () {
    $user = User::factory()->create();
    $project = $user->currentProject();
    $organization = $project->organization;

    $organization->subscriptions()->create([
        'type' => 'default',
        'stripe_id' => 'sub_test123',
        'stripe_status' => 'active',
        'stripe_price' => 'price_test',
        'quantity' => 1,
    ]);

    $this->actingAs($user);

    Livewire::test('pages::app.settings-environment')
        ->call('createEnvironment');

    expect($project->environments()->count())->toBe(2);
});

test('a newly created environment becomes the active one', function () {
    $user = User::factory()->create();
    $project = $user->currentProject();
    $organization = $project->organization;

    $organization->subscriptions()->create([
        'type' => 'default',
        'stripe_id' => 'sub_test123',
        'stripe_status' => 'active',
        'stripe_price' => 'price_test',
        'quantity' => 1,
    ]);

    $this->actingAs($user);

    $component = Livewire::test('pages::app.settings-environment')
        ->call('createEnvironment');

    $newEnvironment = $project->environments()->latest('id')->first();

    expect($component->get('environment')->id)->toBe($newEnvironment->id)
        ->and(session('active_environment_id'))->toBe($newEnvironment->id);
});
