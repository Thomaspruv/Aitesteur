<?php

namespace App\Jobs;

use App\Enums\AiUsageFeature;
use App\Enums\RunStatus;
use App\Enums\Verdict;
use App\Enums\WorkflowStatus;
use App\Models\Run;
use App\Models\RunStep;
use App\Services\Ai\AiUsageRecorder;
use App\Support\AiUsage;
use App\Support\UrlHost;
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
        // Read the environment snapshotted on the run at creation time (set
        // synchronously, in the web request, alongside the switcher's session
        // pick) rather than re-deriving "the" environment here — this job runs
        // in the queue worker, which has no access to that HTTP session, so
        // re-deriving here would silently ignore whichever site the user had
        // actually selected. Falls back to primaryEnvironment() for runs
        // created before this column existed.
        $environment = $this->run->environment ?? $project->primaryEnvironment();

        // Defense in depth: $this->run->environment is a plain FK read, not
        // scoped to $project by the query itself — nothing upstream
        // guarantees the run was actually stamped with an environment
        // belonging to its own workflow's project, so that's re-checked
        // explicitly here rather than trusted.
        if ($environment && $environment->project_id !== $project->id) {
            $this->markFailed("L'environnement associé à ce run n'appartient pas au projet du workflow.");

            return;
        }

        if (! $environment?->url) {
            $this->markFailed("Aucune URL n'est configurée pour cet environnement.");

            return;
        }

        // Defense in depth: the UI already blocks changing an environment's
        // URL without this confirmation, but the crawler now clicks things
        // and attempts logins on whatever URL is configured here, so the job
        // itself must not trust that the UI path was the only way this
        // environment's URL got set.
        if ($message = $environment->authorizationFailureMessage('une exécution')) {
            $this->markFailed($message);

            return;
        }

        $aiClient = $project->aiClient();
        if (! $aiClient) {
            $this->markFailed("Aucun fournisseur IA configuré — l'exécution automatique nécessite une IA. Configurez-la dans Réglages IA.");

            return;
        }

        $steps = collect($workflow->steps)
            ->map(fn (array $step) => ['intent' => trim($step['intent'])])
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

        $decoded = json_decode((string) file_get_contents($outputPath), true);
        @unlink($outputPath);

        // $errorOutput is always a string (never null) by this point — both
        // branches of the try/catch above assign one — but it can be empty
        // when the process failed without writing to stderr, in which case
        // it has nothing to show and the fallback message below should win.
        // ?? wouldn't do that (an empty string isn't null), so this needs ?:.
        if (! is_array($decoded) || empty($decoded['ok'])) {
            $this->markFailed($decoded['error'] ?? ($errorOutput ?: "Résultat d'exécution invalide."));

            return;
        }

        $result = $this->parseExecuteResult($decoded);

        if ($result === null) {
            $this->markFailed("Résultat d'exécution invalide.");

            return;
        }

        $verdict = $result['verdict'];

        if ($verdict === null) {
            $this->persistInterrupted($result, $errorOutput);

            return;
        }

        $this->persist($result, $verdict, $result['escalationLevel'] ?? 0, $durationSeconds);
    }

    /**
     * json_decode() only proves $decoded is *an* array — not that it has the
     * shape persist()/persistInterrupted() need. execute.mjs reliably
     * produces this exact shape (see its own result initialization in
     * main()), but that guarantee crosses a process/language boundary
     * PHPStan can't see across, so this is what actually proves it rather
     * than trusting it blindly — and, as a side effect, turns a malformed/
     * drifted script response into a clean "invalid result" failure instead
     * of a warning-then-TypeError partway through the DB transaction.
     *
     * @param  array<string, mixed>  $decoded
     * @return array{steps: array<int, array{intent: string, state: string, screenshot: string|null, error: string|null, usage: array{promptTokens: int, completionTokens: int}|null}>, verdict: string|null, escalationLevel: int|null, authenticated: bool, aiModel: string|null}|null
     */
    protected function parseExecuteResult(array $decoded): ?array
    {
        $steps = [];
        foreach ((array) ($decoded['steps'] ?? null) as $step) {
            if (! is_array($step) || ! is_string($step['intent'] ?? null) || ! is_string($step['state'] ?? null)) {
                return null;
            }

            $steps[] = [
                'intent' => $step['intent'],
                'state' => $step['state'],
                'screenshot' => is_string($step['screenshot'] ?? null) ? $step['screenshot'] : null,
                'error' => is_string($step['error'] ?? null) ? $step['error'] : null,
                'usage' => $this->parseStepUsage($step['usage'] ?? null),
            ];
        }

        $verdict = $decoded['verdict'] ?? null;
        if ($verdict !== null && ! is_string($verdict)) {
            return null;
        }

        $escalationLevel = $decoded['escalationLevel'] ?? null;
        if ($escalationLevel !== null && ! is_int($escalationLevel)) {
            return null;
        }

        return [
            'steps' => $steps,
            'verdict' => $verdict,
            'escalationLevel' => $escalationLevel,
            'authenticated' => is_bool($decoded['authenticated'] ?? null) ? $decoded['authenticated'] : false,
            'aiModel' => is_string($decoded['aiModel'] ?? null) ? $decoded['aiModel'] : null,
        ];
    }

    /**
     * @return array{promptTokens: int, completionTokens: int}|null
     */
    protected function parseStepUsage(mixed $usage): ?array
    {
        if (! is_array($usage) || ! is_int($usage['promptTokens'] ?? null) || ! is_int($usage['completionTokens'] ?? null)) {
            return null;
        }

        return [
            'promptTokens' => $usage['promptTokens'],
            'completionTokens' => $usage['completionTokens'],
        ];
    }

    protected function normalizeUrl(string $url): string
    {
        return UrlHost::normalize($url);
    }

    /**
     * @param  array<int, array{intent: string, state: string, screenshot: string|null, error: string|null, usage: array{promptTokens: int, completionTokens: int}|null}>  $steps
     */
    protected function createRunSteps(array $steps, ?string $aiModel): void
    {
        $project = $this->run->workflow->project;

        foreach ($steps as $position => $step) {
            RunStep::create([
                'run_id' => $this->run->id,
                'position' => $position,
                'intent' => $step['intent'],
                'state' => Verdict::from($step['state']),
                'screenshot_path' => $this->storeScreenshot($step['screenshot'] ?? null, $position),
            ]);

            if ($step['usage'] !== null && $aiModel !== null) {
                AiUsageRecorder::record(
                    project: $project,
                    feature: AiUsageFeature::RunStep,
                    usage: new AiUsage(
                        promptTokens: $step['usage']['promptTokens'],
                        completionTokens: $step['usage']['completionTokens'],
                        model: $aiModel,
                    ),
                    run: $this->run,
                );
            }
        }
    }

    /**
     * The process was interrupted (e.g. it hit the timeout) after execute.mjs
     * had already written progress for at least one step, but before it could
     * report a final verdict. Persisting the steps that genuinely ran — rather
     * than discarding them via markFailed() — keeps a record of real actions
     * already taken on the live site, so a retry doesn't blindly repeat them.
     *
     * @param  array{steps: array<int, array{intent: string, state: string, screenshot: string|null, error: string|null, usage: array{promptTokens: int, completionTokens: int}|null}>, authenticated: bool, aiModel: string|null}  $result
     */
    protected function persistInterrupted(array $result, ?string $errorOutput): void
    {
        DB::transaction(function () use ($result, $errorOutput) {
            $this->createRunSteps($result['steps'], $result['aiModel']);

            $completed = count($result['steps']);

            $this->run->update([
                'status' => RunStatus::Failed,
                'authenticated' => $result['authenticated'],
                'error' => str("Le run a été interrompu après {$completed} étape(s) exécutée(s) (délai dépassé). ".($errorOutput ?? ''))->limit(500)->toString(),
                'finished_at' => now(),
            ]);
        });
    }

    /**
     * @param  array{steps: array<int, array{intent: string, state: string, screenshot: string|null, error: string|null, usage: array{promptTokens: int, completionTokens: int}|null}>, authenticated: bool, aiModel: string|null}  $result
     */
    protected function persist(array $result, string $verdict, int $escalationLevel, int $durationSeconds): void
    {
        // Resolved before the transaction opens on purpose: Verdict::from()
        // throws on a $verdict string that doesn't match a backing value —
        // parseExecuteResult() only proved it's *a* string — and letting that
        // throw happen after createRunSteps() has already inserted
        // AiUsageEvent rows inside the transaction below would roll back
        // usage records for tokens DeepSeek already billed for. Same
        // reasoning as RunDiscoveryCrawl::persist()'s pre-transaction
        // AI-usage computation.
        $resolvedVerdict = Verdict::from($verdict);

        DB::transaction(function () use ($result, $resolvedVerdict, $escalationLevel, $durationSeconds) {
            $workflow = $this->run->workflow;

            $firstBroken = collect($result['steps'])->firstWhere('state', 'BROKEN');

            $this->createRunSteps($result['steps'], $result['aiModel']);

            $this->run->update([
                'status' => RunStatus::Completed,
                'verdict' => $resolvedVerdict,
                'escalation_level' => $escalationLevel,
                'authenticated' => $result['authenticated'],
                'expected_label' => $firstBroken ? 'étape réussie' : null,
                'observed_label' => $firstBroken ? 'échec : '.$firstBroken['intent'] : null,
                'diagnostic_summary' => $firstBroken['error'] ?? null,
                'finished_at' => now(),
            ]);

            $spark = collect($workflow->spark_data ?? [])
                ->push($durationSeconds)
                ->slice(-8)
                ->values()
                ->all();

            $workflow->update([
                'latest_verdict' => $resolvedVerdict,
                'last_run_at' => now(),
                'spark_data' => $spark,
                // Closes the "Vérifié par replay" promise on Discovery's candidate
                // cards — a candidate is only marked verified once it has actually
                // been replayed successfully, not just proposed by the crawler.
                'verified' => $workflow->status === WorkflowStatus::Candidate && in_array($resolvedVerdict, [Verdict::Pass, Verdict::PassHealed], true)
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
