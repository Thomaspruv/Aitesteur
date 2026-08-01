<?php

use App\Enums\AiProvider;
use App\Enums\DiscoveryRunStatus;
use App\Enums\RunStatus;
use App\Enums\WorkflowStatus;
use App\Jobs\RunDiscoveryCrawl;
use App\Jobs\RunWorkflow;
use App\Models\AppGraphEdge;
use App\Models\AppGraphNode;
use App\Models\User;
use App\Models\Workflow;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

function projectFor(User $user)
{
    return $user->currentProject();
}

test('discovery lists candidate workflows and can activate one', function () {
    $user = User::factory()->create();
    $project = projectFor($user);

    $candidate = Workflow::factory()->candidate()->create([
        'project_id' => $project->id,
        'name' => 'Connexion',
        'score' => 96,
    ]);

    $this->actingAs($user);

    Livewire::test('pages::app.discovery')
        ->assertSee('Connexion')
        ->call('activate', $candidate->id);

    expect($candidate->fresh()->status)->toBe(WorkflowStatus::Active);
});

test('discovery shows the app graph once a crawl has populated it', function () {
    $user = User::factory()->create();
    $project = projectFor($user);

    $node = AppGraphNode::factory()->create(['project_id' => $project->id, 'label' => 'Tableau de bord']);
    AppGraphEdge::factory()->create([
        'project_id' => $project->id,
        'from_node_id' => $node->id,
        'to_node_id' => AppGraphNode::factory()->create(['project_id' => $project->id])->id,
    ]);

    $this->actingAs($user);

    Livewire::test('pages::app.discovery')
        ->assertSee('Tableau de bord')
        ->assertDontSee('Lancez une découverte pour voir apparaître le plan du site.');
});

test('verifying a candidate dispatches RunWorkflow when AI is configured', function () {
    Queue::fake();

    $user = User::factory()->create();
    $project = projectFor($user);
    $project->update(['ai_provider' => AiProvider::DeepSeek, 'ai_model' => 'deepseek-chat', 'ai_api_key' => 'sk-test']);

    $candidate = Workflow::factory()->candidate()->create(['project_id' => $project->id, 'verified' => false]);

    $this->actingAs($user);

    Livewire::test('pages::app.discovery')->call('verifyCandidate', $candidate->id);

    Queue::assertPushed(RunWorkflow::class, fn ($job) => $job->run->workflow_id === $candidate->id);
    expect($candidate->runs()->first()->status)->toBe(RunStatus::Queued);
});

test('verifying a candidate is blocked when no AI is configured', function () {
    Queue::fake();

    $user = User::factory()->create();
    $candidate = Workflow::factory()->candidate()->create(['project_id' => projectFor($user)->id, 'verified' => false]);

    $this->actingAs($user);

    Livewire::test('pages::app.discovery')->call('verifyCandidate', $candidate->id);

    Queue::assertNotPushed(RunWorkflow::class);
});

test('discovery can ignore a candidate', function () {
    $user = User::factory()->create();
    $project = projectFor($user);

    $candidate = Workflow::factory()->candidate()->create(['project_id' => $project->id]);

    $this->actingAs($user);

    Livewire::test('pages::app.discovery')->call('ignore', $candidate->id);

    expect($candidate->fresh()->status)->toBe(WorkflowStatus::Ignored);
});

test('the ignored tab shows ignored candidates and hides them from the pending tab', function () {
    $user = User::factory()->create();
    $project = projectFor($user);

    $pending = Workflow::factory()->candidate()->create(['project_id' => $project->id, 'name' => 'Candidat en attente']);
    $ignored = Workflow::factory()->create(['project_id' => $project->id, 'name' => 'Candidat ignoré', 'status' => WorkflowStatus::Ignored]);

    $this->actingAs($user);

    Livewire::test('pages::app.discovery')
        ->assertSee('Candidat en attente')
        ->assertDontSee('Candidat ignoré')
        ->set('candidateFilter', 'ignored')
        ->assertSee('Candidat ignoré')
        ->assertDontSee('Candidat en attente');
});

test('restoreCandidate moves an ignored workflow back to candidate', function () {
    $user = User::factory()->create();
    $project = projectFor($user);

    $ignored = Workflow::factory()->create(['project_id' => $project->id, 'status' => WorkflowStatus::Ignored]);

    $this->actingAs($user);

    Livewire::test('pages::app.discovery')
        ->set('candidateFilter', 'ignored')
        ->call('restoreCandidate', $ignored->id);

    expect($ignored->fresh()->status)->toBe(WorkflowStatus::Candidate);
});

test('a user cannot restore a candidate belonging to another organization', function () {
    $owner = User::factory()->create();
    $intruder = User::factory()->create();

    $ignored = Workflow::factory()->create(['project_id' => projectFor($owner)->id, 'status' => WorkflowStatus::Ignored]);

    $this->actingAs($intruder);

    Livewire::test('pages::app.discovery')
        ->call('restoreCandidate', $ignored->id)
        ->assertForbidden();

    expect($ignored->fresh()->status)->toBe(WorkflowStatus::Ignored);
});

test('discovery compiler creates a new candidate workflow from a description', function () {
    $user = User::factory()->create();
    $project = projectFor($user);

    $this->actingAs($user);

    Livewire::test('pages::app.discovery')
        ->set('description', "Un utilisateur se connecte, crée une facture de 100 € pour Dupont, l'envoie.")
        ->call('compile');

    $created = $project->workflows()->where('status', WorkflowStatus::Candidate)->first();

    expect($created)->not->toBeNull()
        ->and($created->verified)->toBeFalse();
});

test('discovery compiler uses the configured AI provider when available', function () {
    $user = User::factory()->create();
    $project = projectFor($user);
    $project->update(['ai_provider' => AiProvider::DeepSeek, 'ai_model' => 'deepseek-chat', 'ai_api_key' => 'sk-test']);

    Http::fake([
        'api.deepseek.com/*' => Http::response([
            'choices' => [[
                'message' => ['content' => json_encode([
                    'name' => 'Créer une facture',
                    'criticality' => 'P0',
                    'steps' => [['intent' => 'Créer la facture', 'assertions' => []]],
                ])],
            ]],
        ]),
    ]);

    $this->actingAs($user);

    Livewire::test('pages::app.discovery')
        ->set('description', "Un utilisateur se connecte, crée une facture de 100 € pour Dupont, l'envoie.")
        ->call('compile');

    $created = $project->workflows()->where('status', WorkflowStatus::Candidate)->first();

    expect($created->name)->toBe('Créer une facture')
        ->and($created->criticality->value)->toBe('P0')
        ->and($created->steps)->toBe([['intent' => 'Créer la facture', 'assertions' => []]]);
});

test('discovery compiler falls back to the raw sentence when the AI call fails', function () {
    $user = User::factory()->create();
    $project = projectFor($user);
    $project->update(['ai_provider' => AiProvider::DeepSeek, 'ai_model' => 'deepseek-chat', 'ai_api_key' => 'sk-test']);

    Http::fake(['api.deepseek.com/*' => Http::response('server error', 500)]);

    $this->actingAs($user);

    Livewire::test('pages::app.discovery')
        ->set('description', "Un utilisateur se connecte, crée une facture de 100 € pour Dupont, l'envoie.")
        ->call('compile');

    $created = $project->workflows()->where('status', WorkflowStatus::Candidate)->first();

    expect($created)->not->toBeNull()
        ->and($created->steps[0]['intent'])->toBe("Un utilisateur se connecte, crée une facture de 100 € pour Dupont, l'envoie.");
});

test('launching a discovery run saves the environment url and dispatches the crawl job', function () {
    Queue::fake();

    $user = User::factory()->create();
    $project = projectFor($user);

    $this->actingAs($user);

    Livewire::test('pages::app.discovery')
        ->set('crawlUrl', 'https://staging.example.com')
        ->set('crawlUsername', 'demo')
        ->set('crawlPassword', 'secret')
        ->set('targetAuthorized', true)
        ->call('launchDiscovery');

    expect($project->primaryEnvironment()->url)->toBe('https://staging.example.com')
        ->and($project->primaryEnvironment()->username)->toBe('demo')
        ->and($project->primaryEnvironment()->target_authorized_at)->not->toBeNull()
        ->and($project->latestDiscoveryRun()->status)->toBe(DiscoveryRunStatus::Queued);

    Queue::assertPushed(RunDiscoveryCrawl::class, fn ($job) => $job->discoveryRun->project_id === $project->id);
});

test('relaunching a discovery without retyping credentials keeps the ones already stored', function () {
    Queue::fake();

    $user = User::factory()->create();
    $project = projectFor($user);

    $this->actingAs($user);

    Livewire::test('pages::app.discovery')
        ->set('crawlUrl', 'https://staging.example.com')
        ->set('crawlUsername', 'demo')
        ->set('crawlPassword', 'secret')
        ->set('targetAuthorized', true)
        ->call('launchDiscovery');

    $project->latestDiscoveryRun()->update(['status' => DiscoveryRunStatus::Completed]);

    // Relaunch with only the URL changed — the credential fields are blank,
    // as they always are on a fresh page load (the password is never
    // redisplayed). This must not wipe what's already saved.
    Livewire::test('pages::app.discovery')
        ->set('crawlUrl', 'https://staging.example.com/app')
        ->call('launchDiscovery');

    expect($project->primaryEnvironment()->url)->toBe('https://staging.example.com/app')
        ->and($project->primaryEnvironment()->username)->toBe('demo')
        ->and($project->primaryEnvironment()->password)->toBe('secret');
});

test('the credentials-saved chip appears once an environment has a stored username, and not before', function () {
    $user = User::factory()->create();
    $project = projectFor($user);

    $this->actingAs($user);

    Livewire::test('pages::app.discovery')
        ->assertDontSee('Identifiants enregistrés pour ce site');

    $project->primaryEnvironment()->update(['username' => 'demo', 'password' => 'secret']);

    Livewire::test('pages::app.discovery')
        ->assertSee('Identifiants enregistrés pour ce site')
        ->assertSee('demo');
});

test('the discovery report shows the stats from the latest completed run', function () {
    $user = User::factory()->create();
    $project = projectFor($user);
    $environment = $project->primaryEnvironment();

    $project->discoveryRuns()->create([
        'environment_id' => $environment->id,
        'status' => DiscoveryRunStatus::Completed,
        'pages_visited' => 14,
        'candidates_created' => 3,
        'forms_found' => 5,
        'authenticated' => true,
        'login_form_detected' => true,
        'register_form_detected' => false,
    ]);

    $this->actingAs($user);

    Livewire::test('pages::app.discovery')
        ->assertSee('Rapport de la dernière découverte')
        ->assertSeeInOrder(['Pages explorées', '14'])
        ->assertSeeInOrder(['Écrans avec formulaire', '5'])
        ->assertSeeInOrder(['Workflows candidats', '3']);
});

test('launching a discovery run rejects a malformed url', function () {
    Queue::fake();

    $user = User::factory()->create();

    $this->actingAs($user);

    Livewire::test('pages::app.discovery')
        ->set('crawlUrl', 'not a url at all !!')
        ->call('launchDiscovery')
        ->assertHasErrors('crawlUrl');

    Queue::assertNotPushed(RunDiscoveryCrawl::class);
});

test('launching a discovery run rejects the application\'s own host', function () {
    Queue::fake();

    $user = User::factory()->create();

    $this->actingAs($user);

    Livewire::test('pages::app.discovery')
        ->set('crawlUrl', 'http://localhost/some-path')
        ->call('launchDiscovery')
        ->assertHasErrors('crawlUrl');

    Queue::assertNotPushed(RunDiscoveryCrawl::class);
});

test('launching a discovery run while one is already in progress is blocked', function () {
    Queue::fake();

    $user = User::factory()->create();
    $project = projectFor($user);
    $environment = $project->primaryEnvironment();
    $project->discoveryRuns()->create(['environment_id' => $environment->id, 'status' => DiscoveryRunStatus::Running]);

    $this->actingAs($user);

    Livewire::test('pages::app.discovery')
        ->set('crawlUrl', 'https://staging.example.com')
        ->call('launchDiscovery');

    Queue::assertNotPushed(RunDiscoveryCrawl::class);
    expect($project->discoveryRuns()->count())->toBe(1);
});

test('launching too many discoveries in a short window is rate limited', function () {
    Queue::fake();

    $user = User::factory()->create();
    $project = projectFor($user);

    $this->actingAs($user);

    for ($i = 0; $i < 5; $i++) {
        Livewire::test('pages::app.discovery')
            ->set('crawlUrl', 'https://staging.example.com')
            ->set('targetAuthorized', true)
            ->call('launchDiscovery');

        $project->latestDiscoveryRun()->update(['status' => DiscoveryRunStatus::Completed]);
    }

    Livewire::test('pages::app.discovery')
        ->set('crawlUrl', 'https://staging.example.com')
        ->set('targetAuthorized', true)
        ->call('launchDiscovery');

    Queue::assertPushed(RunDiscoveryCrawl::class, 5);
});

test('compiling too many descriptions in a short window is rate limited', function () {
    $user = User::factory()->create();
    $project = projectFor($user);

    $this->actingAs($user);

    for ($i = 0; $i < 20; $i++) {
        Livewire::test('pages::app.discovery')
            ->set('description', "Description de test numéro {$i} pour dépasser la limite.")
            ->call('compile');
    }

    Livewire::test('pages::app.discovery')
        ->set('description', 'Une description de plus qui devrait être bloquée par la limite.')
        ->call('compile');

    expect($project->workflows()->where('status', WorkflowStatus::Candidate)->count())->toBe(20);
});

test('a user cannot activate a workflow belonging to another organization', function () {
    $owner = User::factory()->create();
    $intruder = User::factory()->create();

    $candidate = Workflow::factory()->candidate()->create([
        'project_id' => projectFor($owner)->id,
    ]);

    $this->actingAs($intruder);

    Livewire::test('pages::app.discovery')
        ->call('activate', $candidate->id)
        ->assertForbidden();
});
