<?php

use App\Enums\AiUsageFeature;
use App\Models\AiUsageEvent;
use App\Models\User;
use Livewire\Livewire;

test('it sums cost per project and respects the date range filter', function () {
    $admin = User::factory()->create(['is_admin' => true]);
    $this->actingAs($admin);

    $projectA = User::factory()->create()->currentProject();
    $projectB = User::factory()->create()->currentProject();

    AiUsageEvent::factory()->create(['project_id' => $projectA->id, 'feature' => AiUsageFeature::RunStep, 'cost_usd' => 1.5, 'created_at' => now()]);
    AiUsageEvent::factory()->create(['project_id' => $projectA->id, 'feature' => AiUsageFeature::RunStep, 'cost_usd' => 0.5, 'created_at' => now()]);
    AiUsageEvent::factory()->create(['project_id' => $projectB->id, 'feature' => AiUsageFeature::DiscoveryAnnotation, 'cost_usd' => 3.0, 'created_at' => now()]);
    // Outside the default 30-day window — must not be counted.
    AiUsageEvent::factory()->create(['project_id' => $projectA->id, 'feature' => AiUsageFeature::RunStep, 'cost_usd' => 100.0, 'created_at' => now()->subDays(90)]);

    $component = Livewire::test('pages::admin.dashboard');

    $rows = collect($component->instance()->rows)->keyBy(fn ($row) => $row['project']->id);

    expect($rows[$projectA->id]['cost_usd'])->toBe(2.0)
        ->and($rows[$projectB->id]['cost_usd'])->toBe(3.0);
});

test('narrowing the date range excludes events outside it', function () {
    $admin = User::factory()->create(['is_admin' => true]);
    $this->actingAs($admin);

    $project = User::factory()->create()->currentProject();

    AiUsageEvent::factory()->create(['project_id' => $project->id, 'cost_usd' => 4.0, 'created_at' => now()->subDays(5)]);

    $component = Livewire::test('pages::admin.dashboard')
        ->set('from', now()->subDays(2)->toDateString())
        ->set('to', now()->toDateString());

    $rows = collect($component->instance()->rows)->keyBy(fn ($row) => $row['project']->id);

    expect($rows[$project->id]['cost_usd'])->toBe(0.0);
});
