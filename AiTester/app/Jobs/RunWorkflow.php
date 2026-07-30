<?php

namespace App\Jobs;

use App\Enums\RunStatus;
use App\Enums\Verdict;
use App\Enums\WorkflowStatus;
use App\Models\Run;
use App\Models\RunStep;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;

class RunWorkflow implements ShouldQueue
{
    use Queueable;

    /**
     * Worst case is MAX_STEPS(15) x 2 attempts x (AI_TIMEOUT_MS 20s + action
     * timeout 15s + settle 4s) ≈ 1170s in execute.mjs — 900s covers realistic
     * runs (AI calls rarely hit their full timeout) without being unbounded.
     */
    private const PROCESS_TIMEOUT_SECONDS = 900;

    public int $timeout = 950;

    /**
     * Never let Laravel silently retry a run — a stale retry would replay
     * real actions on the target site a second time without the caller
     * knowing, and retries are exactly what would let a transient failure
     * keep the run stuck instead of surfacing as Failed. One attempt, then
     * fail cleanly.
     */
    public int $tries = 1;

    public function __construct(public Run $run)
    {
        //
    }

    public function handle(): void
    {
        $this->run->update(['status' => RunStatus::Running, 'started_at' => now()]);

        try {
            $this->execute();
        } catch (\Throwable $exception) {
            report($exception);
            $this->markFailed($exception->getMessage() ?: "Une erreur inattendue est survenue pendant l'exécution.");
        }
    }

    /**
     * Called by the queue worker if the job throws and is not retried — a
     * safety net for anything that slips past the try/catch in handle()
     * itself. Without this, an unanticipated exception leaves the run stuck
     * at Running forever with no way for the user to retry.
     */
    public function failed(?\Throwable $exception): void
    {
        if ($this->run->fresh()?->isInProgress()) {
            $this->markFailed($exception?->getMessage() ?: "Une erreur inattendue est survenue pendant l'exécution.");
        }
    }

    protected function execute(): void
    {
        $workflow = $this->run->workflow;
        $project = $workflow->project;
        $environment = $project->primaryEnvironment();

        if (! $environment?->url) {
            $this->markFailed("Aucune URL n'est configurée pour cet environnement.");

            return;
        }

        $aiClient = $project->aiClient();
        if (! $aiClient) {
            $this->markFailed("Aucun fournisseur IA configuré — l'exécution automatique nécessite une IA. Configurez-la dans Réglages IA.");

            return;
        }

        $steps = collect($workflow->steps)
            ->map(fn (array $step) => ['intent' => trim($step['intent'] ?? '')])
            // A blank intent has nothing for the AI to act on — sending it
            // anyway wastes a real, billed API call and produces a confusing
            // "cant_find" diagnostic that never mentions the actual cause.
            ->reject(fn (array $step) => $step['intent'] === '')
            ->values()
            ->all();

        if ($steps === []) {
            $this->markFailed("Ce workflow n'a aucune étape avec une intention renseignée à exécuter.");

            return;
        }

        Storage::disk('local')->makeDirectory('runs');
        $stepsPath = Storage::disk('local')->path("runs/{$this->run->id}-steps.json");
        $outputPath = Storage::disk('local')->path("runs/{$this->run->id}-output.json");
        file_put_contents($stepsPath, json_encode($steps));

        $command = [
            'node',
            base_path('crawler/execute.mjs'),
            '--url='.$this->normalizeUrl($environment->url),
            '--steps-file='.$stepsPath,
            '--output='.$outputPath,
        ];

        $env = [
            'AI_PROVIDER' => $project->ai_provider->value,
            'AI_MODEL' => $project->ai_model ?? '',
            'AI_API_KEY' => $project->ai_api_key,
        ];
        if ($environment->username && $environment->password) {
            $env['CRAWL_USERNAME'] = $environment->username;
            $env['CRAWL_PASSWORD'] = $environment->password;
        }

        $startedAt = now();

        try {
            $process = Process::timeout(self::PROCESS_TIMEOUT_SECONDS)->env($env)->run($command);
            $errorOutput = $process->errorOutput();
        } catch (\Throwable $exception) {
            // A timeout (or other Process-level failure) still leaves whatever
            // partial output execute.mjs wrote before being killed — it writes
            // progressively, after every step — so real actions already taken
            // on the live site aren't silently lost below; only the process
            // itself failing to start produces no output file at all.
            $errorOutput = $exception->getMessage();
        }

        $durationSeconds = max(1, (int) round($startedAt->diffInSeconds(now())));

        @unlink($stepsPath);

        if (! is_file($outputPath)) {
            $this->markFailed($errorOutput ?: "Le script d'exécution n'a produit aucun résultat.");

            return;
        }

        $result = json_decode((string) file_get_contents($outputPath), true);
        @unlink($outputPath);

        if (! is_array($result) || empty($result['ok'])) {
            $this->markFailed($result['error'] ?? $errorOutput ?? "Résultat d'exécution invalide.");

            return;
        }

        if (($result['verdict'] ?? null) === null) {
            $this->persistInterrupted($result, $errorOutput);

            return;
        }

        $this->persist($result, $durationSeconds);
    }

    protected function normalizeUrl(string $url): string
    {
        return str_starts_with($url, 'http://') || str_starts_with($url, 'https://')
            ? $url
            : 'https://'.$url;
    }

    /**
     * @param  array<int, array{intent: string, state: string, screenshot: string|null, error: string|null}>  $steps
     */
    protected function createRunSteps(array $steps): void
    {
        foreach ($steps as $position => $step) {
            RunStep::create([
                'run_id' => $this->run->id,
                'position' => $position,
                'intent' => $step['intent'],
                'state' => Verdict::from($step['state']),
                'screenshot_path' => $this->storeScreenshot($step['screenshot'] ?? null, $position),
            ]);
        }
    }

    /**
     * The process was interrupted (e.g. it hit the timeout) after execute.mjs
     * had already written progress for at least one step, but before it could
     * report a final verdict. Persisting the steps that genuinely ran — rather
     * than discarding them via markFailed() — keeps a record of real actions
     * already taken on the live site, so a retry doesn't blindly repeat them.
     *
     * @param  array{steps: array<int, array{intent: string, state: string, screenshot: string|null, error: string|null}>}  $result
     */
    protected function persistInterrupted(array $result, ?string $errorOutput): void
    {
        DB::transaction(function () use ($result, $errorOutput) {
            $this->createRunSteps($result['steps']);

            $completed = count($result['steps']);

            $this->run->update([
                'status' => RunStatus::Failed,
                'authenticated' => $result['authenticated'] ?? null,
                'error' => str("Le run a été interrompu après {$completed} étape(s) exécutée(s) (délai dépassé). ".($errorOutput ?? ''))->limit(500)->toString(),
                'finished_at' => now(),
            ]);
        });
    }

    /**
     * @param  array{steps: array<int, array{intent: string, state: string, screenshot: string|null, error: string|null}>, verdict: string, escalationLevel: int, authenticated?: bool}  $result
     */
    protected function persist(array $result, int $durationSeconds): void
    {
        DB::transaction(function () use ($result, $durationSeconds) {
            $workflow = $this->run->workflow;

            $firstBroken = collect($result['steps'])->firstWhere('state', 'BROKEN');

            $this->createRunSteps($result['steps']);

            $verdict = Verdict::from($result['verdict']);

            $this->run->update([
                'status' => RunStatus::Completed,
                'verdict' => $verdict,
                'escalation_level' => $result['escalationLevel'],
                'authenticated' => $result['authenticated'] ?? false,
                'expected_label' => $firstBroken ? 'étape réussie' : null,
                'observed_label' => $firstBroken ? 'échec : '.($firstBroken['intent'] ?? '') : null,
                'diagnostic_summary' => $firstBroken['error'] ?? null,
                'finished_at' => now(),
            ]);

            $spark = collect($workflow->spark_data ?? [])
                ->push($durationSeconds)
                ->slice(-8)
                ->values()
                ->all();

            $workflow->update([
                'latest_verdict' => $verdict,
                'last_run_at' => now(),
                'spark_data' => $spark,
                // Closes the "Vérifié par replay" promise on Discovery's candidate
                // cards — a candidate is only marked verified once it has actually
                // been replayed successfully, not just proposed by the crawler.
                'verified' => $workflow->status === WorkflowStatus::Candidate && in_array($verdict, [Verdict::Pass, Verdict::PassHealed], true)
                    ? true
                    : $workflow->verified,
            ]);
        });
    }

    protected function storeScreenshot(?string $dataUrl, int $position): ?string
    {
        if (! $dataUrl || ! str_starts_with($dataUrl, 'data:image/jpeg;base64,')) {
            return null;
        }

        $binary = base64_decode(substr($dataUrl, strlen('data:image/jpeg;base64,')), true);
        if ($binary === false) {
            return null;
        }

        $path = "runs/{$this->run->id}/step-{$position}.jpg";
        Storage::disk('public')->put($path, $binary);

        return $path;
    }

    protected function markFailed(string $error): void
    {
        $this->run->update([
            'status' => RunStatus::Failed,
            'error' => str($error)->limit(500)->toString(),
            'finished_at' => now(),
        ]);
    }
}
