<?php

use App\Enums\Criticality;
use App\Enums\Verdict;
use App\Enums\WorkflowStatus;
use App\Models\Run;
use App\Models\User;
use App\Models\Workflow;
use Livewire\Livewire;

test('guests are redirected to the login page', function () {
    $response = $this->get(route('dashboard'));
    $response->assertRedirect(route('login'));
});

test('authenticated users can visit the dashboard', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $response = $this->get(route('dashboard'));
    $response->assertOk();
});

test('dashboard shows an empty state for a project with no active workflows', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    Livewire::test('pages::app.dashboard')
        ->assertSee('Aucun workflow surveillé pour le moment');
});

test('dashboard lists active workflows sorted by criticality and hides candidates', function () {
    $user = User::factory()->create();
    $project = $user->currentProject();

    Workflow::factory()->create(['project_id' => $project->id, 'name' => 'Low priority', 'criticality' => Criticality::P2, 'status' => WorkflowStatus::Active]);
    Workflow::factory()->create(['project_id' => $project->id, 'name' => 'Top priority', 'criticality' => Criticality::P0, 'status' => WorkflowStatus::Active]);
    Workflow::factory()->candidate()->create(['project_id' => $project->id, 'name' => 'Not yet activated']);

    $this->actingAs($user);

    $component = Livewire::test('pages::app.dashboard')
        ->assertSee('Top priority')
        ->assertSee('Low priority')
        ->assertDontSee('Not yet activated');

    $names = $component->instance()->workflows->pluck('name')->all();

    expect($names)->toBe(['Top priority', 'Low priority']);
});

test('sparklinePoints normalizes large values (real run durations) into the viewbox range', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $component = Livewire::test('pages::app.dashboard');

    // Real run durations can be 30-300+ seconds, far outside the old
    // raw-y-coordinate behavior's implicit 0-20 assumption.
    $points = $component->instance()->sparklinePoints([30, 90, 300]);

    foreach (explode(' ', $points) as $point) {
        [, $y] = explode(',', $point);
        expect((float) $y)->toBeGreaterThanOrEqual(0)->toBeLessThanOrEqual(20);
    }
});

test('sparklinePoints handles a constant series without dividing by zero', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $component = Livewire::test('pages::app.dashboard');

    $points = $component->instance()->sparklinePoints([10, 10, 10]);

    expect($points)->not->toContain('NAN')->not->toContain('INF');
});

test('dashboard false-positive-rate stat is a percentage of unconfirmed broken runs in the last 30 days', function () {
    $user = User::factory()->create();
    $project = $user->currentProject();

    $workflow = Workflow::factory()->create(['project_id' => $project->id, 'status' => WorkflowStatus::Active]);

    Run::factory()->create(['workflow_id' => $workflow->id, 'verdict' => Verdict::Pass, 'confirmed' => false]);
    Run::factory()->create(['workflow_id' => $workflow->id, 'verdict' => Verdict::Broken, 'confirmed' => false]);
    Run::factory()->create(['workflow_id' => $workflow->id, 'verdict' => Verdict::Broken, 'confirmed' => true]);
    Run::factory()->create(['workflow_id' => $workflow->id, 'verdict' => Verdict::Pass, 'confirmed' => false, 'created_at' => now()->subDays(45)]);

    $this->actingAs($user);

    $stats = Livewire::test('pages::app.dashboard')->instance()->stats;

    $falsePositiveStat = collect($stats)->firstWhere('label', 'Faux positifs (30j)');

    expect($falsePositiveStat['value'])->toBe('33.3%');
});
