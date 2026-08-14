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
            $usage = $this->inner->lastUsage();

            if ($usage !== null) {
                // Recording usage is bookkeeping, not the primary operation —
                // if it throws (e.g. a transient DB error), it must not
                // replace whatever the try block above produced (a successful
                // result, or the real exception that caused it to fail).
                // Unguarded, an exception here also wouldn't be an
                // AiClientException, so it would evade every caller's
                // catch (AiClientException) and surface as an unrelated crash.
                try {
                    AiUsageRecorder::record(
                        project: $this->project,
                        feature: $this->feature,
                        usage: $usage,
                        run: $this->run,
                        discoveryRun: $this->discoveryRun,
                    );
                } catch (\Throwable $exception) {
                    report($exception);
                }
            }
        }
    }

    public function lastUsage(): ?AiUsage
    {
        return $this->inner->lastUsage();
    }
}
