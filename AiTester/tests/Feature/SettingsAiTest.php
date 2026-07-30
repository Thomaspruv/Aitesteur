<?php

use App\Enums\AiProvider;
use App\Models\User;
use Livewire\Livewire;

test('it saves the provider, model and encrypted api key', function () {
    $user = User::factory()->create();
    $project = $user->currentProject();

    $this->actingAs($user);

    Livewire::test('pages::app.settings-ai')
        ->set('aiProvider', AiProvider::DeepSeek->value)
        ->set('aiModel', 'deepseek-chat')
        ->set('aiApiKey', 'sk-secret')
        ->call('save');

    $project->refresh();

    expect($project->ai_provider)->toBe(AiProvider::DeepSeek)
        ->and($project->ai_model)->toBe('deepseek-chat')
        ->and($project->ai_api_key)->toBe('sk-secret');

    expect($project->getRawOriginal('ai_api_key'))->not->toContain('sk-secret');
});

test('an ai model that does not belong to the selected provider is rejected', function () {
    $user = User::factory()->create();

    $this->actingAs($user);

    Livewire::test('pages::app.settings-ai')
        ->set('aiProvider', AiProvider::DeepSeek->value)
        ->set('aiModel', 'gpt-4-not-a-deepseek-model')
        ->call('save')
        ->assertHasErrors('aiModel');
});

test('resubmitting with a blank api key does not wipe the existing one', function () {
    $user = User::factory()->create();
    $project = $user->currentProject();
    $project->update(['ai_provider' => AiProvider::DeepSeek, 'ai_model' => 'deepseek-chat', 'ai_api_key' => 'sk-existing']);

    $this->actingAs($user);

    Livewire::test('pages::app.settings-ai')
        ->set('aiModel', 'deepseek-reasoner')
        ->call('save');

    expect($project->refresh()->ai_api_key)->toBe('sk-existing')
        ->and($project->ai_model)->toBe('deepseek-reasoner');
});
