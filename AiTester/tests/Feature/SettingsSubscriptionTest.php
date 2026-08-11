<?php

use App\Models\User;
use Livewire\Livewire;

test('guests are redirected to the login page', function () {
    $response = $this->get(route('settings-abonnement'));
    $response->assertRedirect(route('login'));
});

test('an organization with no subscription sees the free plan and an upgrade cta', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    Livewire::test('pages::app.settings-abonnement')
        ->assertSee('Plan gratuit')
        ->assertSee('Passer à Pro')
        ->assertDontSee('Plan Pro actif');
});

test('an organization with an active subscription sees the pro plan and a manage-billing cta', function () {
    $user = User::factory()->create();
    $organization = $user->currentProject()->organization;

    $organization->subscriptions()->create([
        'type' => 'default',
        'stripe_id' => 'sub_test123',
        'stripe_status' => 'active',
        'stripe_price' => 'price_test',
        'quantity' => 1,
    ]);

    $this->actingAs($user);

    Livewire::test('pages::app.settings-abonnement')
        ->assertSee('Plan Pro actif')
        ->assertSee('Gérer mon abonnement')
        ->assertDontSee('Passer à Pro');
});

test('subscribing without a configured price shows a warning instead of crashing', function () {
    config(['services.stripe.price_pro' => null]);

    $user = User::factory()->create();
    $this->actingAs($user);

    Livewire::test('pages::app.settings-abonnement')
        ->call('subscribeToPro')
        ->assertNoRedirect();
});
