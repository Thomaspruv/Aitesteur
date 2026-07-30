<?php

namespace App\Services\Ai;

use App\Contracts\AiClient;
use App\Exceptions\AiClientException;
use Illuminate\Support\Facades\Http;

class DeepSeekClient implements AiClient
{
    public function __construct(
        protected string $apiKey,
        protected string $model = 'deepseek-chat',
    ) {}

    public function chatJson(string $systemPrompt, string $userPrompt): array
    {
        $response = Http::withToken($this->apiKey)
            ->timeout(30)
            ->post('https://api.deepseek.com/chat/completions', [
                'model' => $this->model,
                'messages' => [
                    ['role' => 'system', 'content' => $systemPrompt],
                    ['role' => 'user', 'content' => $userPrompt],
                ],
                'response_format' => ['type' => 'json_object'],
            ]);

        if ($response->failed()) {
            throw new AiClientException("Échec de l'appel à DeepSeek : ".$response->status());
        }

        $content = $response->json('choices.0.message.content');

        if (! is_string($content)) {
            throw new AiClientException('Réponse DeepSeek inattendue.');
        }

        $decoded = json_decode($content, true);

        if (! is_array($decoded)) {
            throw new AiClientException("La réponse de DeepSeek n'est pas un JSON valide.");
        }

        return $decoded;
    }
}
