<?php

use App\Contracts\AiClient;
use App\Exceptions\AiClientException;
use App\Services\Ai\WorkflowStepCompiler;

function fakeAiClient(array $response): AiClient
{
    return new class($response) implements AiClient
    {
        public function __construct(private array $response) {}

        public function chatJson(string $systemPrompt, string $userPrompt): array
        {
            return $this->response;
        }
    };
}

test('it normalizes a well-formed ai response into workflow steps', function () {
    $compiler = new WorkflowStepCompiler(fakeAiClient([
        'name' => 'Créer une facture',
        'criticality' => 'P0',
        'steps' => [
            ['intent' => 'Se connecter', 'assertions' => [['label' => 'état atteint', 'on' => true]]],
            ['intent' => 'Créer la facture', 'assertions' => []],
        ],
    ]));

    $result = $compiler->compile('Un utilisateur se connecte et crée une facture');

    expect($result['name'])->toBe('Créer une facture')
        ->and($result['criticality'])->toBe('P0')
        ->and($result['steps'])->toHaveCount(2)
        ->and($result['steps'][0]['assertions'][0])->toBe(['label' => 'état atteint', 'on' => true]);
});

test('it falls back to a truncated description and P1 when name/criticality are missing', function () {
    $compiler = new WorkflowStepCompiler(fakeAiClient([
        'steps' => [['intent' => 'Faire quelque chose', 'assertions' => []]],
    ]));

    $result = $compiler->compile('Une description assez longue pour être tronquée si besoin');

    expect($result['criticality'])->toBe('P1')
        ->and($result['name'])->not->toBeEmpty();
});

test('it throws when the ai response has no usable steps', function () {
    $compiler = new WorkflowStepCompiler(fakeAiClient(['name' => 'X', 'steps' => []]));

    $compiler->compile('description');
})->throws(AiClientException::class);

test('it throws when a step is missing an intent', function () {
    $compiler = new WorkflowStepCompiler(fakeAiClient([
        'steps' => [['assertions' => []]],
    ]));

    $compiler->compile('description');
})->throws(AiClientException::class);

test('it throws when a step intent is whitespace only', function () {
    $compiler = new WorkflowStepCompiler(fakeAiClient([
        'steps' => [['intent' => "   \n\t", 'assertions' => []]],
    ]));

    $compiler->compile('description');
})->throws(AiClientException::class);

test('it falls back to the description when the ai name is whitespace only', function () {
    $compiler = new WorkflowStepCompiler(fakeAiClient([
        'name' => '   ',
        'steps' => [['intent' => 'Faire quelque chose', 'assertions' => []]],
    ]));

    $result = $compiler->compile('Une description de secours');

    expect($result['name'])->toBe('Une description de secours');
});

test('it caps the number of steps and assertions to bounded limits', function () {
    $compiler = new WorkflowStepCompiler(fakeAiClient([
        'steps' => array_fill(0, 30, [
            'intent' => 'Étape',
            'assertions' => array_fill(0, 10, ['label' => 'assertion', 'on' => true]),
        ]),
    ]));

    $result = $compiler->compile('description');

    expect($result['steps'])->toHaveCount(15)
        ->and($result['steps'][0]['assertions'])->toHaveCount(5);
});
