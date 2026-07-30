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
        ->call('save');

    $environment = $project->primaryEnvironment()->refresh();

    expect($environment->url)->toBe('https://staging.example.com')
        ->and($environment->username)->toBe('demo')
        ->and($environment->password)->toBe('secret');
});

test('resubmitting with a blank password does not wipe the existing one', function () {
    $user = User::factory()->create();
    $project = $user->currentProject();
    $project->primaryEnvironment()->update(['url' => 'https://old.example.com', 'username' => 'demo', 'password' => 'sk-existing']);

    $this->actingAs($user);

    Livewire::test('pages::app.settings-environment')
        ->set('url', 'https://new.example.com')
        ->call('save');

    $environment = $project->primaryEnvironment()->refresh();

    expect($environment->url)->toBe('https://new.example.com')
        ->and($environment->username)->toBe('demo')
        ->and($environment->password)->toBe('sk-existing');
});

test('it creates a primary environment on the fly if none exists yet', function () {
    $user = User::factory()->create();
    $project = $user->currentProject();
    $project->environments()->delete();

    $this->actingAs($user);

    Livewire::test('pages::app.settings-environment')
        ->set('url', 'https://example.com')
        ->call('save');

    expect($project->primaryEnvironment())->not->toBeNull()
        ->and($project->primaryEnvironment()->url)->toBe('https://example.com');
});
