<?php

// USD per million tokens. Rates are a snapshot at the time this file was
// last edited — App\Services\Ai\AiUsageRecorder reads whatever is here *at
// insert time* and stores the resulting cost_usd on the event row, so a
// later edit here never retroactively rewrites historical costs.
return [
    'deepseek' => [
        'deepseek-chat' => [
            'input_per_million' => 0.27,
            'output_per_million' => 1.10,
        ],
        'deepseek-reasoner' => [
            'input_per_million' => 0.55,
            'output_per_million' => 2.19,
        ],
        'default' => [
            'input_per_million' => 0.27,
            'output_per_million' => 1.10,
        ],
    ],
];
