<?php

use App\Enums\AiProvider;
use App\Enums\Criticality;
use App\Enums\DiscoveryRunStatus;
use App\Enums\WorkflowOrigin;
use App\Enums\WorkflowStatus;
use App\Jobs\RunDiscoveryCrawl;
use App\Models\DiscoveryRun;
use App\Models\User;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;

const DISCOVERY_TINY_JPEG_BASE64 = '/9j/4AAQSkZJRgABAQAAAQABAAD/2wBDAAMCAgICAgMCAgIDAwMDBAYEBAQEBAgGBgUGCQgKCgkICQkKDA8MCgsOCwkJDRENDg8QEBEQCgwSExIQEw8QEBD/2wBDAQMDAwQDBAgEBAgQCwkLEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBD/wAARCAABAAEDASIAAhEBAxEB/8QAFQABAQAAAAAAAAAAAAAAAAAAAAf/xAAUEAEAAAAAAAAAAAAAAAAAAAAA/8QAFQEBAQAAAAAAAAAAAAAAAAAAAAX/xAAUEQEAAAAAAAAAAAAAAAAAAAAA/9oADAMBAAIRAxEAPwCdABmX/9k=';

function fakeCrawlOutput(callable $resultFor): void
{
    Process::fake(function ($process) use ($resultFor) {
        $outputPath = collect($process->command)
            ->first(fn ($part) => str_starts_with($part, '--output='));
        $outputPath = substr($outputPath, strlen('--output='));

        file_put_contents($outputPath, json_encode($resultFor($process)));

        return Process::result();
    });
}

test('a successful crawl persists the app graph and candidate workflows', function () {
    $user = User::factory()->create();
    $project = $user->currentProject();
    $environment = $project->environments()->first();
    $environment->update(['url' => 'https://example.com']);

    $discoveryRun = DiscoveryRun::factory()->create([
        'project_id' => $project->id,
        'environment_id' => $environment->id,
    ]);

    fakeCrawlOutput(fn () => [
        'ok' => true,
        'nodes' => [
            ['key' => '/', 'heading' => 'Accueil', 'url' => 'https://example.com/', 'kind' => 'current', 'x' => 20, 'y' => 20],
            ['key' => '/login', 'heading' => 'Connexion', 'url' => 'https://example.com/login', 'kind' => 'neutral', 'x' => 60, 'y' => 20],
        ],
        'edges' => [
            ['from' => '/', 'to' => '/login'],
        ],
        'formPages' => [],
        'loginFormDetected' => true,
        'registerFormDetected' => false,
        'pagesVisited' => 2,
        'authenticated' => false,
        'error' => null,
    ]);

    (new RunDiscoveryCrawl($discoveryRun))->handle();

    $discoveryRun->refresh();

    expect($discoveryRun->status)->toBe(DiscoveryRunStatus::Completed)
        ->and($discoveryRun->pages_visited)->toBe(2)
        ->and($discoveryRun->candidates_created)->toBe(1)
        ->and($discoveryRun->authenticated)->toBeFalse()
        ->and($discoveryRun->login_form_detected)->toBeTrue()
        ->and($discoveryRun->register_form_detected)->toBeFalse()
        ->and($discoveryRun->forms_found)->toBe(1);

    expect($project->appGraphNodes()->count())->toBe(2);
    expect($project->appGraphEdges()->count())->toBe(1);
    expect($project->appGraphNodes()->where('key', '/login')->value('url'))->toBe('https://example.com/login');
    expect($project->workflows()->where('status', WorkflowStatus::Candidate)->where('name', 'Connexion')->exists())->toBeTrue();
});

test('graph nodes sharing the same heading fall back to a path-based label instead of all sharing one name', function () {
    $user = User::factory()->create();
    $project = $user->currentProject();
    $environment = $project->environments()->first();
    $environment->update(['url' => 'https://example.com']);

    $discoveryRun = DiscoveryRun::factory()->create([
        'project_id' => $project->id,
        'environment_id' => $environment->id,
    ]);

    fakeCrawlOutput(fn () => [
        'ok' => true,
        'nodes' => [
            // Simulates a single-page app whose <h1> is a persistent site
            // logo ("Maestro") rather than a per-route heading.
            ['key' => '/', 'heading' => 'Maestro', 'url' => 'https://example.com/', 'kind' => 'current', 'x' => 20, 'y' => 20],
            ['key' => '/billing', 'heading' => 'Maestro', 'url' => 'https://example.com/billing', 'kind' => 'neutral', 'x' => 60, 'y' => 20],
            ['key' => '/team', 'heading' => 'Équipe', 'url' => 'https://example.com/team', 'kind' => 'neutral', 'x' => 100, 'y' => 20],
        ],
        'edges' => [],
        'formPages' => [],
        'loginFormDetected' => false,
        'registerFormDetected' => false,
        'pagesVisited' => 3,
        'authenticated' => false,
        'error' => null,
    ]);

    (new RunDiscoveryCrawl($discoveryRun))->handle();

    $labels = $project->appGraphNodes()->pluck('label', 'key');

    expect($labels['/'])->toBe('Accueil')
        ->and($labels['/billing'])->toBe('Billing')
        ->and($labels['/team'])->toBe('Équipe');
});

test('re-running discovery does not duplicate an already-proposed candidate', function () {
    $user = User::factory()->create();
    $project = $user->currentProject();
    $environment = $project->environments()->first();
    $environment->update(['url' => 'https://example.com']);

    $project->workflows()->create([
        'name' => 'Connexion',
        'criticality' => Criticality::P0,
        'origin' => WorkflowOrigin::Discovered,
        'status' => WorkflowStatus::Candidate,
        'steps' => [],
    ]);

    $discoveryRun = DiscoveryRun::factory()->create([
        'project_id' => $project->id,
        'environment_id' => $environment->id,
    ]);

    fakeCrawlOutput(fn () => [
        'ok' => true,
        'nodes' => [],
        'edges' => [],
        'formPages' => [],
        'loginFormDetected' => true,
        'registerFormDetected' => false,
        'pagesVisited' => 0,
        'authenticated' => false,
        'error' => null,
    ]);

    (new RunDiscoveryCrawl($discoveryRun))->handle();

    expect($discoveryRun->fresh()->candidates_created)->toBe(0);
    expect($project->workflows()->where('name', 'Connexion')->count())->toBe(1);
});

test('form pages with a colliding title fall back to a humanized, deduplicated path name', function () {
    $user = User::factory()->create();
    $project = $user->currentProject();
    $environment = $project->environments()->first();
    $environment->update(['url' => 'https://example.com']);

    $discoveryRun = DiscoveryRun::factory()->create([
        'project_id' => $project->id,
        'environment_id' => $environment->id,
    ]);

    fakeCrawlOutput(fn () => [
        'ok' => true,
        'nodes' => [],
        'edges' => [],
        'formPages' => [
            ['title' => 'App', 'url' => 'https://example.com/settings/billing', 'heading' => null, 'fields' => []],
            ['title' => 'App', 'url' => 'https://example.com/settings/team', 'heading' => null, 'fields' => []],
        ],
        'loginFormDetected' => false,
        'registerFormDetected' => false,
        'pagesVisited' => 2,
        'authenticated' => false,
        'error' => null,
    ]);

    (new RunDiscoveryCrawl($discoveryRun))->handle();

    $names = $project->workflows()->where('status', WorkflowStatus::Candidate)->pluck('name')->all();

    expect($names)->toBe([
        'Remplir le formulaire — Settings › Billing',
        'Remplir le formulaire — Settings › Team',
    ]);
});

test('a trailing uuid segment is dropped instead of truncated mid-uuid', function () {
    $user = User::factory()->create();
    $project = $user->currentProject();
    $environment = $project->environments()->first();
    $environment->update(['url' => 'https://example.com']);

    $discoveryRun = DiscoveryRun::factory()->create([
        'project_id' => $project->id,
        'environment_id' => $environment->id,
    ]);

    fakeCrawlOutput(fn () => [
        'ok' => true,
        'nodes' => [],
        'edges' => [],
        'formPages' => [
            ['title' => '', 'url' => 'https://example.com/projects/06868607-b7b6-41aa-9b9e-e00b1c2d3e4f', 'heading' => null, 'fields' => []],
        ],
        'loginFormDetected' => false,
        'registerFormDetected' => false,
        'pagesVisited' => 1,
        'authenticated' => false,
        'error' => null,
    ]);

    (new RunDiscoveryCrawl($discoveryRun))->handle();

    $workflow = $project->workflows()->where('status', WorkflowStatus::Candidate)->first();

    expect($workflow->name)->toBe('Remplir le formulaire — Projects (détail)')
        ->and($workflow->steps[0]['intent'])->toBe('Ouvrir Projects (détail)');
});

test('a uuid in the middle of the path is dropped, not just a trailing one', function () {
    $user = User::factory()->create();
    $project = $user->currentProject();
    $environment = $project->environments()->first();
    $environment->update(['url' => 'https://example.com']);

    $discoveryRun = DiscoveryRun::factory()->create([
        'project_id' => $project->id,
        'environment_id' => $environment->id,
    ]);

    fakeCrawlOutput(fn () => [
        'ok' => true,
        'nodes' => [],
        'edges' => [],
        'formPages' => [
            // The project UUID sits in the MIDDLE of the path here (real bug
            // reported against the Maestro site: /projects/{uuid}/settings,
            // /projects/{uuid}/foundation, /projects/{uuid}/tasks/{uuid} all
            // rendered as the identical truncated "Projects › <36-char-uuid…"
            // because only a trailing ID segment was ever stripped).
            ['title' => '', 'url' => 'https://example.com/projects/06868607-b7b6-41aa-9b9e-e00b1c2d3e4f/settings', 'heading' => null, 'fields' => []],
            ['title' => '', 'url' => 'https://example.com/projects/06868607-b7b6-41aa-9b9e-e00b1c2d3e4f/foundation', 'heading' => null, 'fields' => []],
        ],
        'loginFormDetected' => false,
        'registerFormDetected' => false,
        'pagesVisited' => 2,
        'authenticated' => false,
        'error' => null,
    ]);

    (new RunDiscoveryCrawl($discoveryRun))->handle();

    $names = $project->workflows()->where('status', WorkflowStatus::Candidate)->orderBy('id')->pluck('name')->all();

    expect($names)->toBe([
        'Remplir le formulaire — Projects › Settings',
        'Remplir le formulaire — Projects › Foundation',
    ]);
});

test('re-crawling the same page does not duplicate it even if the generated name changed', function () {
    $user = User::factory()->create();
    $project = $user->currentProject();
    $environment = $project->environments()->first();
    $environment->update(['url' => 'https://example.com']);

    // Simulate a prior run that already proposed this exact page under an older name.
    $project->workflows()->create([
        'name' => 'Ancien nom pour cette page',
        'criticality' => Criticality::P2,
        'origin' => WorkflowOrigin::Discovered,
        'discovery_url' => 'https://example.com/settings/billing',
        'status' => WorkflowStatus::Candidate,
        'steps' => [],
    ]);

    $discoveryRun = DiscoveryRun::factory()->create([
        'project_id' => $project->id,
        'environment_id' => $environment->id,
    ]);

    fakeCrawlOutput(fn () => [
        'ok' => true,
        'nodes' => [],
        'edges' => [],
        'formPages' => [
            // Same URL, but this run's naming (deterministic fallback) would produce
            // a different name than what's already stored — must still be treated
            // as the same page, not a new candidate.
            ['title' => 'Nouveau titre différent', 'url' => 'https://example.com/settings/billing', 'heading' => null, 'fields' => []],
        ],
        'loginFormDetected' => false,
        'registerFormDetected' => false,
        'pagesVisited' => 1,
        'authenticated' => false,
        'error' => null,
    ]);

    (new RunDiscoveryCrawl($discoveryRun))->handle();

    expect($discoveryRun->fresh()->candidates_created)->toBe(0);
    expect($project->workflows()->where('discovery_url', 'https://example.com/settings/billing')->count())->toBe(1);
});

test('form pages are named by the ai annotator when the project has one configured', function () {
    $user = User::factory()->create();
    $project = $user->currentProject();
    $project->update(['ai_provider' => AiProvider::DeepSeek, 'ai_model' => 'deepseek-chat', 'ai_api_key' => 'sk-test']);
    $environment = $project->environments()->first();
    $environment->update(['url' => 'https://example.com']);

    Http::fake([
        'api.deepseek.com/*' => Http::response([
            'choices' => [[
                'message' => ['content' => json_encode([
                    'pages' => [
                        ['url' => 'https://example.com/settings/billing', 'name' => 'Mettre à jour la facturation', 'criticality' => 'P1'],
                    ],
                ])],
            ]],
        ]),
    ]);

    $discoveryRun = DiscoveryRun::factory()->create([
        'project_id' => $project->id,
        'environment_id' => $environment->id,
    ]);

    fakeCrawlOutput(fn () => [
        'ok' => true,
        'nodes' => [],
        'edges' => [],
        'formPages' => [
            ['title' => 'App', 'url' => 'https://example.com/settings/billing', 'heading' => 'Facturation', 'fields' => ['card_number']],
        ],
        'loginFormDetected' => false,
        'registerFormDetected' => false,
        'pagesVisited' => 1,
        'authenticated' => false,
        'error' => null,
    ]);

    (new RunDiscoveryCrawl($discoveryRun))->handle();

    $workflow = $project->workflows()->where('status', WorkflowStatus::Candidate)->first();

    expect($workflow->name)->toBe('Mettre à jour la facturation')
        ->and($workflow->criticality->value)->toBe('P1');
});

test('a crawl error marks the discovery run as failed', function () {
    $user = User::factory()->create();
    $project = $user->currentProject();
    $environment = $project->environments()->first();
    $environment->update(['url' => 'https://not-a-real-domain.invalid']);

    $discoveryRun = DiscoveryRun::factory()->create([
        'project_id' => $project->id,
        'environment_id' => $environment->id,
    ]);

    fakeCrawlOutput(fn () => ['ok' => false, 'error' => "L'URL fournie n'est pas valide."]);

    (new RunDiscoveryCrawl($discoveryRun))->handle();

    $discoveryRun->refresh();

    expect($discoveryRun->status)->toBe(DiscoveryRunStatus::Failed)
        ->and($discoveryRun->error)->toBe("L'URL fournie n'est pas valide.");
});

test('missing crawl output marks the discovery run as failed', function () {
    $user = User::factory()->create();
    $project = $user->currentProject();
    $environment = $project->environments()->first();
    $environment->update(['url' => 'https://example.com']);

    $discoveryRun = DiscoveryRun::factory()->create([
        'project_id' => $project->id,
        'environment_id' => $environment->id,
    ]);

    Process::fake(fn () => Process::result(errorOutput: 'node: command not found'));

    (new RunDiscoveryCrawl($discoveryRun))->handle();

    expect($discoveryRun->fresh()->status)->toBe(DiscoveryRunStatus::Failed);
});

test('an unanticipated exception during persist marks the run failed instead of leaving it stuck running', function () {
    $user = User::factory()->create();
    $project = $user->currentProject();
    $project->update(['ai_provider' => AiProvider::DeepSeek, 'ai_model' => 'deepseek-chat', 'ai_api_key' => 'sk-test']);
    $environment = $project->environments()->first();
    $environment->update(['url' => 'https://example.com']);

    // Simulate the real-world failure mode found in review: DeepSeek is unreachable
    // (a network-level ConnectionException, not the handled AiClientException).
    Http::fake(function () {
        throw new ConnectionException('Could not resolve host.');
    });

    $discoveryRun = DiscoveryRun::factory()->create([
        'project_id' => $project->id,
        'environment_id' => $environment->id,
    ]);

    fakeCrawlOutput(fn () => [
        'ok' => true,
        'nodes' => [],
        'edges' => [],
        'formPages' => [
            ['title' => 'App', 'url' => 'https://example.com/settings', 'heading' => null, 'fields' => []],
        ],
        'loginFormDetected' => false,
        'registerFormDetected' => false,
        'pagesVisited' => 1,
        'authenticated' => false,
        'error' => null,
    ]);

    (new RunDiscoveryCrawl($discoveryRun))->handle();

    expect($discoveryRun->fresh()->status)->toBe(DiscoveryRunStatus::Failed);
});

test('the failed() hook marks an in-progress run failed as a safety net', function () {
    $user = User::factory()->create();
    $project = $user->currentProject();
    $environment = $project->environments()->first();

    $discoveryRun = DiscoveryRun::factory()->create([
        'project_id' => $project->id,
        'environment_id' => $environment->id,
        'status' => DiscoveryRunStatus::Running,
    ]);

    (new RunDiscoveryCrawl($discoveryRun))->failed(new RuntimeException('Le worker a été tué.'));

    expect($discoveryRun->fresh()->status)->toBe(DiscoveryRunStatus::Failed)
        ->and($discoveryRun->fresh()->error)->toBe('Le worker a été tué.');
});

test('popup and modal nodes discovered while clicking are persisted, labeled, counted, and screenshotted', function () {
    Storage::fake('public');

    $user = User::factory()->create();
    $project = $user->currentProject();
    $environment = $project->environments()->first();
    $environment->update(['url' => 'https://example.com']);

    $discoveryRun = DiscoveryRun::factory()->create([
        'project_id' => $project->id,
        'environment_id' => $environment->id,
    ]);

    fakeCrawlOutput(fn () => [
        'ok' => true,
        'nodes' => [
            ['key' => '/', 'heading' => 'Accueil', 'url' => 'https://example.com/', 'kind' => 'current', 'x' => 20, 'y' => 20],
            ['key' => 'modal:/:0', 'heading' => 'Confirmer la suppression', 'url' => null, 'kind' => 'modal', 'x' => 60, 'y' => 20, 'screenshot' => 'data:image/jpeg;base64,'.DISCOVERY_TINY_JPEG_BASE64],
            ['key' => 'popup:https://help.example.org/chat', 'heading' => 'Support chat', 'url' => 'https://help.example.org/chat', 'kind' => 'popup', 'x' => 100, 'y' => 20, 'screenshot' => null],
        ],
        'edges' => [
            ['from' => '/', 'to' => 'modal:/:0'],
            ['from' => '/', 'to' => 'popup:https://help.example.org/chat'],
        ],
        'formPages' => [],
        'loginFormDetected' => false,
        'registerFormDetected' => false,
        'pagesVisited' => 1,
        'authenticated' => false,
        'error' => null,
    ]);

    (new RunDiscoveryCrawl($discoveryRun))->handle();

    $discoveryRun->refresh();

    expect($discoveryRun->popups_found)->toBe(1)
        ->and($discoveryRun->modals_found)->toBe(1);

    $modalNode = $project->appGraphNodes()->where('key', 'modal:/:0')->firstOrFail();
    expect($modalNode->screenshot_path)->not->toBeNull();
    Storage::disk('public')->assertExists($modalNode->screenshot_path);

    $popupNode = $project->appGraphNodes()->where('key', 'popup:https://help.example.org/chat')->firstOrFail();
    expect($popupNode->screenshot_path)->toBeNull();

    $labels = $project->appGraphNodes()->pluck('label', 'key');

    expect($labels['modal:/:0'])->toBe('Confirmer la suppression')
        ->and($labels['popup:https://help.example.org/chat'])->toBe('Support chat');

    expect($project->appGraphEdges()->count())->toBe(2);
});
