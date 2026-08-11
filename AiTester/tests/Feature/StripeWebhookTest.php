<?php

use App\Models\User;
use Illuminate\Testing\TestResponse;

function postSignedStripeWebhook(array $payload): TestResponse
{
    $body = json_encode($payload);
    $timestamp = time();
    $signature = hash_hmac('sha256', "{$timestamp}.{$body}", config('cashier.webhook.secret'));

    return test()->postJson('stripe/webhook', $payload, [
        'Stripe-Signature' => "t={$timestamp},v1={$signature}",
    ]);
}

beforeEach(function () {
    config(['cashier.webhook.secret' => 'whsec_test']);
});

test('a webhook with an invalid signature is rejected', function () {
    $response = $this->postJson('stripe/webhook', [
        'type' => 'customer.subscription.updated',
    ], [
        'Stripe-Signature' => 't='.time().',v1=not-the-real-signature',
    ]);

    $response->assertForbidden();
});

test('a subscription updated webhook syncs the local subscription status', function () {
    $organization = User::factory()->create()->currentProject()->organization;
    $organization->forceFill(['stripe_id' => 'cus_test123'])->save();

    $subscription = $organization->subscriptions()->create([
        'type' => 'default',
        'stripe_id' => 'sub_test123',
        'stripe_status' => 'active',
        'stripe_price' => 'price_test',
        'quantity' => 1,
    ]);

    $response = postSignedStripeWebhook([
        'id' => 'evt_test123',
        'type' => 'customer.subscription.updated',
        'data' => [
            'object' => [
                'id' => 'sub_test123',
                'customer' => 'cus_test123',
                'status' => 'past_due',
                'cancel_at_period_end' => false,
                'items' => [
                    'data' => [[
                        'id' => 'si_test123',
                        'quantity' => 1,
                        'price' => ['id' => 'price_test', 'product' => 'prod_test'],
                    ]],
                ],
            ],
        ],
    ]);

    $response->assertOk();
    expect($subscription->refresh()->stripe_status)->toBe('past_due');
});

test('a subscription deleted webhook cancels the local subscription', function () {
    $organization = User::factory()->create()->currentProject()->organization;
    $organization->forceFill(['stripe_id' => 'cus_test123'])->save();

    $subscription = $organization->subscriptions()->create([
        'type' => 'default',
        'stripe_id' => 'sub_test123',
        'stripe_status' => 'active',
        'stripe_price' => 'price_test',
        'quantity' => 1,
    ]);

    $response = postSignedStripeWebhook([
        'id' => 'evt_test456',
        'type' => 'customer.subscription.deleted',
        'data' => [
            'object' => [
                'id' => 'sub_test123',
                'customer' => 'cus_test123',
            ],
        ],
    ]);

    $response->assertOk();
    expect($subscription->refresh()->stripe_status)->toBe('canceled');
});
