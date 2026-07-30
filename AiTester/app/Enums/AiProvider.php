<?php

namespace App\Enums;

enum AiProvider: string
{
    case DeepSeek = 'deepseek';

    public function label(): string
    {
        return match ($this) {
            self::DeepSeek => 'DeepSeek',
        };
    }

    /**
     * @return array<string, string> model value => display label
     */
    public function models(): array
    {
        return match ($this) {
            self::DeepSeek => [
                'deepseek-chat' => 'DeepSeek Chat',
                'deepseek-reasoner' => 'DeepSeek Reasoner',
            ],
        };
    }
}
