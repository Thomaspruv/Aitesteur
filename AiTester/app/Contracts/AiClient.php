<?php

namespace App\Contracts;

use App\Exceptions\AiClientException;
use App\Support\AiUsage;

interface AiClient
{
    /**
     * Send a chat completion request expecting a JSON object response and
     * return it decoded as an associative array.
     *
     * @return array<string, mixed>
     *
     * @throws AiClientException if the request fails or the response isn't valid JSON
     */
    public function chatJson(string $systemPrompt, string $userPrompt): array;

    /**
     * Token usage from the most recent chatJson() call, or null if none has
     * been made yet (or the last one failed before a response was received).
     */
    public function lastUsage(): ?AiUsage;
}
