<?php

use Illuminate\Support\Facades\Route;
use Laravel\Cashier\Http\Controllers\WebhookController;

Route::view('/', 'welcome')->name('home');

// Stripe posts subscription lifecycle events here (cancellations, failed
// payments, plan changes) so stripe_status stays in sync outside of the
// synchronous checkout flow. CSRF is exempted for 'stripe/*' in
// bootstrap/app.php; Cashier's own WebhookController verifies authenticity
// via the Stripe-Signature header instead (see config/cashier.php).
Route::post('stripe/webhook', [WebhookController::class, 'handleWebhook'])->name('cashier.webhook');

require __DIR__.'/app.php';
require __DIR__.'/settings.php';
require __DIR__.'/admin.php';
