<?php

namespace App\Services\Ai;

use App\Contracts\AiClient;
use App\Exceptions\AiClientException;
use App\Support\AiUsage;
use Illuminate\Support\Facades\Http;

class DeepSeekClient implements AiClient
{
    protected ?AiUsage $lastUsage = null;

    public function __construct(
        protected string $apiKey,
        protected string $model = 'deepseek-chat',
    ) {}

    public function chatJson(string $systemPrompt, string $userPrompt): array
    {
        // Reset first: a connection-level failure below never reaches the
        // capture further down, and lastUsage() must not then report stale
        // usage from a previous, unrelated call.
        $this->lastUsage = null;

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

        // Captured before content validation below — DeepSeek bills these
        // tokens even if the response content turns out not to be valid JSON.
        $this->lastUsage = new AiUsage(
            promptTokens: (int) $response->json('usage.prompt_tokens', 0),
            completionTokens: (int) $response->json('usage.completion_tokens', 0),
            model: $response->json('model', $this->model),
        );

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

    public function lastUsage(): ?AiUsage
    {
        return $this->lastUsage;
    }
}
