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
    $project->primaryEnvironment()->update([
        'url' => 'https://staging.example.com',
        'target_authorized_at' => now()->subDay(),
        'target_authorized_host' => 'staging.example.com',
    ]);

    $this->actingAs($user);

    Livewire::test('pages::app.settings-environment')
        ->set('username', 'demo')
        ->call('save')
        ->assertHasNoErrors();

    expect($project->primaryEnvironment()->refresh()->username)->toBe('demo');
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
