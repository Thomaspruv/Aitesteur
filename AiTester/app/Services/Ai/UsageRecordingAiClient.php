<?php

namespace App\Services\Ai;

use App\Contracts\AiClient;
use App\Enums\AiUsageFeature;
use App\Models\DiscoveryRun;
use App\Models\Project;
use App\Models\Run;
use App\Support\AiUsage;

class UsageRecordingAiClient implements AiClient
{
    public function __construct(
        protected AiClient $inner,
        protected Project $project,
        protected AiUsageFeature $feature,
        protected ?Run $run = null,
        protected ?DiscoveryRun $discoveryRun = null,
    ) {}

    public function chatJson(string $systemPrompt, string $userPrompt): array
    {
        try {
            return $this->inner->chatJson($systemPrompt, $userPrompt);
        } finally {
            if ($this->inner->lastUsage() !== null) {
                AiUsageRecorder::record(
                    project: $this->project,
                    feature: $this->feature,
                    usage: $this->inner->lastUsage(),
                    run: $this->run,
                    discoveryRun: $this->discoveryRun,
                );
            }
        }
    }

    public function lastUsage(): ?AiUsage
    {
        return $this->inner->lastUsage();
    }
}
