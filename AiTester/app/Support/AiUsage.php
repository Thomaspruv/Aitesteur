<?php

namespace App\Support;

final readonly class AiUsage
{
    public function __construct(
        public int $promptTokens,
        public int $completionTokens,
        public string $model,
    ) {}

    public function totalTokens(): int
    {
        return $this->promptTokens + $this->completionTokens;
    }
}
