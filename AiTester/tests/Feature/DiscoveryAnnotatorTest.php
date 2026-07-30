<?php

use App\Contracts\AiClient;
use App\Exceptions\AiClientException;
use App\Services\Ai\DiscoveryAnnotator;

function fakeAnnotatorClient(array $response): AiClient
{
    return new class($response) implements AiClient
    {
        public function __construct(public array $response) {}

        public function chatJson(string $systemPrompt, string $userPrompt): array
        {
            return $this->response;
        }
    };
}

test('it maps ai annotations back to their page url', function () {
    $annotator = new DiscoveryAnnotator(fakeAnnotatorClient([
        'pages' => [
            ['url' => 'https://app.test/settings/billing', 'name' => 'Mettre à jour la facturation', 'criticality' => 'P1'],
            ['url' => 'https://app.test/settings/team', 'name' => "Inviter un membre de l'équipe", 'criticality' => 'P1'],
        ],
    ]));

    $annotations = $annotator->annotate([
        ['title' => 'Maestro', 'url' => 'https://app.test/settings/billing', 'heading' => 'Facturation', 'fields' => ['card_number']],
        ['title' => 'Maestro', 'url' => 'https://app.test/settings/team', 'heading' => null, 'fields' => ['email']],
    ]);

    expect($annotations)->toHaveCount(2)
        ->and($annotations['https://app.test/settings/billing']['name'])->toBe('Mettre à jour la facturation')
        ->and($annotations['https://app.test/settings/team']['criticality'])->toBe('P1');
});

test('it returns an empty map for an empty input without calling the ai', function () {
    $annotator = new DiscoveryAnnotator(fakeAnnotatorClient(['pages' => []]));

    expect($annotator->annotate([]))->toBe([]);
});

test('it throws when the ai response has no usable pages', function () {
    $annotator = new DiscoveryAnnotator(fakeAnnotatorClient(['pages' => []]));

    $annotator->annotate([
        ['title' => 'X', 'url' => 'https://app.test/x', 'heading' => null, 'fields' => []],
    ]);
})->throws(AiClientException::class);

test('it skips a whitespace-only name instead of producing a blank candidate', function () {
    $annotator = new DiscoveryAnnotator(fakeAnnotatorClient([
        'pages' => [
            ['url' => 'https://app.test/blank', 'name' => "   \n", 'criticality' => 'P1'],
            ['url' => 'https://app.test/valid', 'name' => 'Nom valide', 'criticality' => 'P1'],
        ],
    ]));

    $annotations = $annotator->annotate([
        ['title' => 'X', 'url' => 'https://app.test/blank', 'heading' => null, 'fields' => []],
        ['title' => 'Y', 'url' => 'https://app.test/valid', 'heading' => null, 'fields' => []],
    ]);

    expect($annotations)->toHaveCount(1)
        ->and($annotations)->toHaveKey('https://app.test/valid');
});
