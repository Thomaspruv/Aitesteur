<?php

use App\Exceptions\AiClientException;
use App\Services\Ai\DeepSeekClient;
use Illuminate\Support\Facades\Http;

test('it sends the expected request shape and decodes a JSON response', function () {
    Http::fake([
        'api.deepseek.com/*' => Http::response([
            'choices' => [
                ['message' => ['content' => '{"name":"Connexion","steps":[]}']],
            ],
        ]),
    ]);

    $client = new DeepSeekClient('sk-test', 'deepseek-chat');
    $result = $client->chatJson('system prompt', 'user prompt');

    expect($result)->toBe(['name' => 'Connexion', 'steps' => []]);

    Http::assertSent(function ($request) {
        return $request->url() === 'https://api.deepseek.com/chat/completions'
            && $request->hasHeader('Authorization', 'Bearer sk-test')
            && $request['model'] === 'deepseek-chat'
            && $request['messages'][0]['role'] === 'system'
            && $request['messages'][1]['content'] === 'user prompt';
    });
});

test('it throws when the http call fails', function () {
    Http::fake(['api.deepseek.com/*' => Http::response('unauthorized', 401)]);

    (new DeepSeekClient('sk-bad'))->chatJson('system', 'user');
})->throws(AiClientException::class);

test('it throws when the response content is not valid json', function () {
    Http::fake([
        'api.deepseek.com/*' => Http::response([
            'choices' => [['message' => ['content' => 'not json']]],
        ]),
    ]);

    (new DeepSeekClient('sk-test'))->chatJson('system', 'user');
})->throws(AiClientException::class);
