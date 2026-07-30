<?php

namespace App\Services\Ai;

use App\Contracts\AiClient;
use App\Exceptions\AiClientException;
use Illuminate\Support\Str;

class WorkflowStepCompiler
{
    /**
     * Bounds on AI-generated content — a verbose or misbehaving model
     * shouldn't be able to write unbounded data into the steps JSON column.
     */
    protected const MAX_STEPS = 15;

    protected const MAX_ASSERTIONS_PER_STEP = 5;

    protected const MAX_INTENT_LENGTH = 200;

    protected const MAX_ASSERTION_LABEL_LENGTH = 100;

    protected const SYSTEM_PROMPT = <<<'PROMPT'
        Tu es un compilateur de tests QA. On te donne une phrase en langage naturel décrivant un parcours utilisateur.
        Découpe-la en étapes atomiques exécutables et réponds UNIQUEMENT avec un objet JSON de cette forme, sans texte autour :
        {
          "name": "titre court du workflow (moins de 60 caractères)",
          "criticality": "P0" | "P1" | "P2",
          "steps": [
            {"intent": "description d'une action atomique", "assertions": [{"label": "ce qui doit être vrai", "on": true}]}
          ]
        }
        "steps" doit contenir au moins une étape (15 maximum). "assertions" peut être un tableau vide si rien d'explicite n'est vérifiable.
        PROMPT;

    public function __construct(protected AiClient $client) {}

    /**
     * @return array{name: string, criticality: string, steps: array<int, array{intent: string, assertions: array<int, array{label: string, on: bool}>}>}
     *
     * @throws AiClientException if the AI response doesn't contain usable steps
     */
    public function compile(string $description): array
    {
        $result = $this->client->chatJson(self::SYSTEM_PROMPT, $description);

        $steps = $result['steps'] ?? null;

        if (! is_array($steps) || $steps === []) {
            throw new AiClientException("La réponse de l'IA ne contient aucune étape exploitable.");
        }

        $normalizedSteps = [];
        foreach (array_slice($steps, 0, self::MAX_STEPS) as $step) {
            $intent = is_string($step['intent'] ?? null) ? trim($step['intent']) : '';

            if (! is_array($step) || $intent === '') {
                throw new AiClientException("La réponse de l'IA contient une étape invalide.");
            }

            $assertions = [];
            foreach (array_slice($step['assertions'] ?? [], 0, self::MAX_ASSERTIONS_PER_STEP) as $assertion) {
                $label = is_array($assertion) && is_string($assertion['label'] ?? null) ? trim($assertion['label']) : '';

                if ($label !== '') {
                    $assertions[] = [
                        'label' => Str::limit($label, self::MAX_ASSERTION_LABEL_LENGTH, ''),
                        'on' => (bool) ($assertion['on'] ?? false),
                    ];
                }
            }

            $normalizedSteps[] = [
                'intent' => Str::limit($intent, self::MAX_INTENT_LENGTH, ''),
                'assertions' => $assertions,
            ];
        }

        if ($normalizedSteps === []) {
            throw new AiClientException("La réponse de l'IA ne contient aucune étape exploitable.");
        }

        $criticality = in_array($result['criticality'] ?? null, ['P0', 'P1', 'P2'], true)
            ? $result['criticality']
            : 'P1';

        $name = is_string($result['name'] ?? null) ? trim($result['name']) : '';
        $name = $name !== '' ? Str::limit($name, 60, '') : Str::limit($description, 60);

        return [
            'name' => $name,
            'criticality' => $criticality,
            'steps' => $normalizedSteps,
        ];
    }
}
