<?php

namespace App\Jobs;

use App\Contracts\AiClient;
use App\Enums\Criticality;
use App\Enums\DiscoveryRunStatus;
use App\Enums\WorkflowOrigin;
use App\Enums\WorkflowStatus;
use App\Exceptions\AiClientException;
use App\Models\AppGraphNode;
use App\Models\DiscoveryRun;
use App\Models\Project;
use App\Services\Ai\DiscoveryAnnotator;
use App\Support\UrlHost;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class RunDiscoveryCrawl implements ShouldQueue
{
    use Queueable;

    public int $timeout = 200;

    /**
     * Never let Laravel silently retry a discovery run — a stale re-attempt
     * would re-crawl a target the user may no longer expect, and retries are
     * exactly what would let a transient failure keep the run stuck instead
     * of surfacing as Failed. One attempt, then fail cleanly.
     */
    public int $tries = 1;

    /**
     * Create a new job instance.
     */
    public function __construct(public DiscoveryRun $discoveryRun)
    {
        //
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $this->discoveryRun->update(['status' => DiscoveryRunStatus::Running, 'started_at' => now()]);

        try {
            $this->run();
        } catch (\Throwable $exception) {
            report($exception);
            $this->markFailed($exception->getMessage() ?: 'Une erreur inattendue est survenue pendant la découverte.');
        }
    }

    /**
     * Called by the queue worker if the job throws and is not retried (or
     * exhausts retries) — a safety net for anything that slips past the
     * try/catch in handle() itself (e.g. a failure in the initial status
     * update). Without this, an unanticipated exception leaves the run
     * stuck at Running forever with no way for the user to retry.
     */
    public function failed(?\Throwable $exception): void
    {
        if ($this->discoveryRun->fresh()?->isInProgress()) {
            $this->markFailed($exception?->getMessage() ?: 'Une erreur inattendue est survenue pendant la découverte.');
        }
    }

    protected function run(): void
    {
        $environment = $this->discoveryRun->environment;

        if (! $environment->url) {
            $this->markFailed("Aucune URL n'est configurée pour cet environnement.");

            return;
        }

        // Defense in depth: the UI already blocks changing an environment's
        // URL without this confirmation, but the crawler now clicks things
        // and attempts logins on whatever URL is configured here, so the job
        // itself must not trust that the UI path was the only way this
        // environment's URL got set.
        if ($message = $environment->authorizationFailureMessage('une découverte')) {
            $this->markFailed($message);

            return;
        }

        Storage::disk('local')->makeDirectory('discovery');
        $outputPath = Storage::disk('local')->path("discovery/{$this->discoveryRun->id}.json");

        $command = [
            'node',
            base_path('crawler/crawl.mjs'),
            '--url='.$this->normalizeUrl($environment->url),
            '--output='.$outputPath,
            '--max-pages=20',
        ];

        $env = [];
        if ($environment->username && $environment->password) {
            $env['CRAWL_USERNAME'] = $environment->username;
            $env['CRAWL_PASSWORD'] = $environment->password;
        }

        $process = Process::timeout(180)->env($env)->run($command);

        if (! is_file($outputPath)) {
            $this->markFailed($process->errorOutput() ?: "Le script de crawl n'a produit aucun résultat.");

            return;
        }

        $result = json_decode((string) file_get_contents($outputPath), true);
        @unlink($outputPath);

        if (! is_array($result) || empty($result['ok'])) {
            $this->markFailed($result['error'] ?? 'Résultat de crawl invalide.');

            return;
        }

        $this->persist($result);
    }

    protected function normalizeUrl(string $url): string
    {
        return UrlHost::normalize($url);
    }

    /**
     * @param  array{nodes: array<int, array{key: string, heading: string|null, url: string|null, kind: string, x: int, y: int, screenshot?: string|null}>, edges: array<int, array{from: string, to: string}>, formPages: array<int, array{title: string, url: string, heading: string|null, fields: array<int, string>}>, loginFormDetected: bool, registerFormDetected: bool, pagesVisited: int, authenticated?: bool}  $result
     */
    protected function persist(array $result): void
    {
        DB::transaction(function () use ($result) {
            $project = $this->discoveryRun->project;

            $project->appGraphEdges()->delete();
            $project->appGraphNodes()->delete();

            // document.title is often the same static string on every route of
            // a client-rendered app, which is why nodes used to all end up
            // labelled with the site's name. A heading is a much better
            // per-page signal, but only when it's actually distinctive within
            // this crawl — otherwise (e.g. every page shares a persistent
            // "Maestro" <h1> logo) fall back to the URL path, which is always
            // unique per node.
            $headingCounts = collect($result['nodes'])->countBy('heading');

            $nodesByKey = [];
            foreach ($result['nodes'] as $index => $node) {
                $nodesByKey[$node['key']] = AppGraphNode::create([
                    'project_id' => $project->id,
                    'key' => $node['key'],
                    'label' => $this->nodeLabel($node, $headingCounts),
                    'url' => $node['url'],
                    'screenshot_path' => $this->storeNodeScreenshot($node['screenshot'] ?? null, $index),
                    'kind' => $node['kind'],
                    'x' => $node['x'],
                    'y' => $node['y'],
                ]);
            }

            foreach ($result['edges'] as $edge) {
                if (! isset($nodesByKey[$edge['from']], $nodesByKey[$edge['to']])) {
                    continue;
                }

                $project->appGraphEdges()->create([
                    'from_node_id' => $nodesByKey[$edge['from']]->id,
                    'to_node_id' => $nodesByKey[$edge['to']]->id,
                ]);
            }

            $candidates = $this->buildCandidates($result, $project);

            // Dedup by source page URL when a candidate has one (form pages) — the
            // generated name can legitimately change between runs (AI naming isn't
            // deterministic, or the deterministic fallback picks a different title),
            // so name-only matching would either drop a genuinely new page whose name
            // happens to collide, or re-propose the same page endlessly under a new
            // name. Candidates without a URL (Connexion/Créer un compte, one-off
            // motifs) still dedup by name.
            $existingCandidates = $project->workflows()
                ->where('status', WorkflowStatus::Candidate)
                ->get(['name', 'discovery_url']);
            $existingUrls = $existingCandidates->pluck('discovery_url')->filter()->all();
            $existingNames = $existingCandidates->pluck('name');

            $candidatesCreated = 0;
            foreach ($candidates as $candidate) {
                $isDuplicate = $candidate['discovery_url']
                    ? in_array($candidate['discovery_url'], $existingUrls, true)
                    : $existingNames->contains($candidate['name']);

                if ($isDuplicate) {
                    continue;
                }

                $project->workflows()->create([
                    'name' => $candidate['name'],
                    'criticality' => Criticality::from($candidate['criticality']),
                    'origin' => WorkflowOrigin::Discovered,
                    'discovery_url' => $candidate['discovery_url'],
                    'status' => WorkflowStatus::Candidate,
                    'score' => $candidate['score'],
                    'verified' => $candidate['verified'],
                    'canary' => $candidate['canary'],
                    'steps' => $candidate['steps'],
                ]);

                $candidatesCreated++;
            }

            $this->discoveryRun->update([
                'status' => DiscoveryRunStatus::Completed,
                'pages_visited' => $result['pagesVisited'],
                'candidates_created' => $candidatesCreated,
                'authenticated' => $result['authenticated'] ?? false,
                'login_form_detected' => $result['loginFormDetected'],
                'register_form_detected' => $result['registerFormDetected'],
                'forms_found' => count($result['formPages'])
                    + ($result['loginFormDetected'] ? 1 : 0)
                    + ($result['registerFormDetected'] ? 1 : 0),
                // Popups/modals are recorded as regular graph nodes (kind
                // "popup"/"modal") by the crawler, so counting them here is
                // just filtering the same node list rather than a separate
                // signal the crawler has to report.
                'popups_found' => collect($result['nodes'])->where('kind', 'popup')->count(),
                'modals_found' => collect($result['nodes'])->where('kind', 'modal')->count(),
                'finished_at' => now(),
            ]);
        });
    }

    /**
     * @param  array{key: string, heading: string|null, url: string|null, kind: string, x: int, y: int}  $node
     */
    protected function nodeLabel(array $node, Collection $headingCounts): string
    {
        // Modal/popup nodes have a synthetic key ("modal:/settings:0",
        // "popup:https://...") rather than a real URL path, so
        // humanizePathFromKey() — built for path segments — isn't a
        // meaningful fallback for them. Their heading (the dialog's own
        // title, or the popup window's <title>) is always the right label.
        if (in_array($node['kind'], ['modal', 'popup'], true)) {
            return Str::limit($node['heading'] ?: ($node['kind'] === 'modal' ? 'Fenêtre modale' : 'Fenêtre externe'), 40, '');
        }

        $heading = $node['heading'];
        $headingIsDistinctive = $heading && ($headingCounts[$heading] ?? 0) <= 1;

        return Str::limit($headingIsDistinctive ? $heading : $this->humanizePathFromKey($node['key']), 40, '');
    }

    /**
     * Builds a human-readable label directly from the crawler's own templated
     * key (e.g. "/projects/{id}/settings") instead of re-deriving ID-ness from
     * the raw URL — the crawler already decided which segments are IDs when it
     * built this key (see templateKey() in crawler/crawl.mjs), so there's no
     * reason for graph node labeling to run a second, independently-maintained
     * heuristic that could silently disagree with it.
     */
    protected function humanizePathFromKey(string $key): string
    {
        $path = trim($key, '/');

        if ($path === '') {
            return 'Accueil';
        }

        $segments = explode('/', $path);
        $isDetail = end($segments) === '{id}';

        $humanized = collect($segments)
            ->reject(fn ($segment) => $segment === '{id}')
            ->map(fn ($segment) => ucfirst(str_replace(['-', '_'], ' ', $segment)))
            ->implode(' › ');

        if ($humanized === '') {
            return $isDetail ? 'Détail' : 'Accueil';
        }

        return $isDetail ? "{$humanized} (détail)" : $humanized;
    }

    /**
     * @param  array{formPages: array<int, array{title: string, url: string, heading: string|null, fields: array<int, string>}>, loginFormDetected: bool, registerFormDetected: bool}  $result
     * @return array<int, array{name: string, criticality: string, canary: bool, score: int, verified: bool, steps: array}>
     */
    protected function buildCandidates(array $result, Project $project): array
    {
        $candidates = [];

        if ($result['loginFormDetected']) {
            $candidates[] = [
                'name' => 'Connexion',
                'criticality' => 'P0',
                'canary' => true,
                'score' => 90,
                'verified' => false,
                'discovery_url' => null,
                'steps' => [
                    ['intent' => 'Ouvrir la page de connexion', 'assertions' => []],
                    ['intent' => 'Saisir les identifiants du compte de test', 'assertions' => []],
                    ['intent' => 'Soumettre le formulaire', 'assertions' => [['label' => 'état atteint', 'on' => true]]],
                ],
            ];
        }

        if ($result['registerFormDetected']) {
            $candidates[] = [
                'name' => 'Créer un compte',
                'criticality' => 'P0',
                'canary' => false,
                'score' => 85,
                'verified' => false,
                'discovery_url' => null,
                'steps' => [
                    ['intent' => 'Ouvrir la page de création de compte', 'assertions' => []],
                    ['intent' => 'Saisir les informations du nouveau compte', 'assertions' => []],
                    ['intent' => 'Soumettre le formulaire', 'assertions' => [['label' => 'état atteint', 'on' => true]]],
                ],
            ];
        }

        return [...$candidates, ...$this->buildFormCandidates($result['formPages'], $project->aiClient())];
    }

    /**
     * Name each generic form page — via the AI annotator when the project has
     * one configured (it understands what the page is actually for from its
     * URL/heading/fields), otherwise a deterministic fallback that only reuses
     * the page title when it's unique among this batch, falling back to the
     * URL path so pages with an identical <title> don't collide.
     *
     * @param  array<int, array{title: string, url: string, heading: string|null, fields: array<int, string>}>  $formPages
     * @return array<int, array{name: string, criticality: string, canary: bool, score: int, verified: bool, steps: array}>
     */
    protected function buildFormCandidates(array $formPages, ?AiClient $aiClient): array
    {
        if ($formPages === []) {
            return [];
        }

        $annotations = [];
        if ($aiClient) {
            try {
                $annotations = (new DiscoveryAnnotator($aiClient))->annotate($formPages);
            } catch (AiClientException) {
                $annotations = [];
            }
        }

        $titleCounts = collect($formPages)->countBy('title');
        $usedNames = [];
        $candidates = [];

        foreach ($formPages as $formPage) {
            $annotation = $annotations[$formPage['url']] ?? null;

            if ($annotation) {
                $baseName = $annotation['name'];
                $criticality = $annotation['criticality'];
            } else {
                $titleIsUnique = $formPage['title'] !== '' && ($titleCounts[$formPage['title']] ?? 0) <= 1;
                $baseName = 'Remplir le formulaire — '.($titleIsUnique ? $formPage['title'] : $this->humanizePath($formPage['url']));
                $criticality = 'P2';
            }

            $name = Str::limit($baseName, 60, '');
            $suffix = 2;
            $original = $name;
            while (in_array($name, $usedNames, true)) {
                $name = Str::limit($original, 55, '')." ({$suffix})";
                $suffix++;
            }
            $usedNames[] = $name;

            $candidates[] = [
                'name' => $name,
                'criticality' => $criticality,
                'canary' => false,
                'score' => $annotation ? 55 : 40,
                'verified' => false,
                'discovery_url' => $formPage['url'],
                'steps' => [
                    ['intent' => 'Ouvrir '.$this->humanizePath($formPage['url']), 'assertions' => []],
                    ['intent' => 'Remplir et soumettre le formulaire', 'assertions' => []],
                ],
            ];
        }

        return $candidates;
    }

    /**
     * Turn a URL path into a human-readable label instead of a raw slug —
     * "/settings/mcp" becomes "Settings › Mcp". Every ID-like segment is
     * dropped, not just a trailing one: "/projects/{uuid}/tasks/{uuid}"
     * becomes "Projects › Tasks (détail)" rather than leaving a 36-character
     * UUID sitting in the middle of the path, which previously ate the whole
     * label budget and truncated away the actual page name that came after it.
     */
    protected function humanizePath(string $url): string
    {
        $path = trim((string) parse_url($url, PHP_URL_PATH), '/');

        if ($path === '') {
            return 'Accueil';
        }

        $segments = explode('/', $path);
        $isDetail = $this->looksLikeId(end($segments));

        $humanized = collect($segments)
            ->reject(fn ($segment) => $this->looksLikeId($segment))
            ->map(fn ($segment) => ucfirst(str_replace(['-', '_'], ' ', $segment)))
            ->implode(' › ');

        if ($humanized === '') {
            return $isDetail ? 'Détail' : 'Accueil';
        }

        return $isDetail ? "{$humanized} (détail)" : $humanized;
    }

    /**
     * Mirrors crawler/crawl.mjs's templateKey() ID heuristic: numeric, a UUID,
     * or a hex-charset segment with at least one digit. Erring towards "not an
     * ID" is deliberate — a real word wrongly dropped is worse than an
     * occasional ID left visible.
     */
    protected function looksLikeId(string $segment): bool
    {
        if (preg_match('/^\d+$/', $segment) === 1) {
            return true;
        }

        if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $segment) === 1) {
            return true;
        }

        return strlen($segment) >= 8 && preg_match('/^[0-9a-f]+$/i', $segment) === 1 && preg_match('/\d/', $segment) === 1;
    }

    /**
     * Only modal/popup nodes carry a screenshot (see crawl.mjs's
     * exploreClicks()/handlePopup()) — a real page's screenshot would just be
     * the page itself, already reachable by URL, so capturing one adds no
     * information a regular AppGraphNode doesn't already have.
     */
    protected function storeNodeScreenshot(?string $dataUrl, int $index): ?string
    {
        if (! $dataUrl || ! str_starts_with($dataUrl, 'data:image/jpeg;base64,')) {
            return null;
        }

        $binary = base64_decode(substr($dataUrl, strlen('data:image/jpeg;base64,')), true);
        if ($binary === false) {
            return null;
        }

        $path = "discovery/{$this->discoveryRun->id}/node-{$index}.jpg";
        Storage::disk('public')->put($path, $binary);

        return $path;
    }

    protected function markFailed(string $error): void
    {
        $this->discoveryRun->update([
            'status' => DiscoveryRunStatus::Failed,
            'error' => str($error)->limit(500)->toString(),
            'finished_at' => now(),
        ]);
    }
}
