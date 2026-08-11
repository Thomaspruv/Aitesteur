<?php

namespace App\Enums;

enum AiUsageFeature: string
{
    case RunStep = 'run_step';
    case WorkflowCompilation = 'workflow_compilation';
    case DiscoveryAnnotation = 'discovery_annotation';

    public function label(): string
    {
        return match ($this) {
            self::RunStep => 'Décision de step (Run)',
            self::WorkflowCompilation => 'Compilation de workflow',
            self::DiscoveryAnnotation => 'Annotation de découverte',
        };
    }
}
