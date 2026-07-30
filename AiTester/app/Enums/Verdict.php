<?php

namespace App\Enums;

enum Verdict: string
{
    case Pass = 'PASS';
    case PassHealed = 'PASS_HEALED';
    case Changed = 'CHANGED';
    case Broken = 'BROKEN';
    case Skipped = 'SKIPPED';

    public function label(): string
    {
        return $this->value;
    }

    /**
     * Tailwind color token suffix, e.g. "pass" -> bg-verdict-pass / text-verdict-pass.
     */
    public function slug(): string
    {
        return match ($this) {
            self::Pass => 'pass',
            self::PassHealed => 'healed',
            self::Changed => 'changed',
            self::Broken => 'broken',
            self::Skipped => 'skipped',
        };
    }
}
