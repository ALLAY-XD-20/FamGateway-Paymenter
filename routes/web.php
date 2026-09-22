<?php

use Illuminate\Support\Facades\Route;
use Paymenter\Extensions\Gateways\FamGateway\FamGateway;

Route::post(
    '/extensions/gateways/famgateway/webhook/{invoiceId}',
    [FamGateway::class, 'webhook']
)->name('extensions.gateways.famgateway.webhook');

Route::get('/extensions/gateways/famgateway/callback/{invoiceId}', function ($invoiceId) {
    // Fallback safety net: the webhook usually marks the invoice paid, but
    // if it's delayed or never arrives, actively verify the order's real
    // status with FamGateway right as the customer lands back on the site.
    app(FamGateway::class)->verifyPaymentStatus($invoiceId);

    return redirect()->route('invoices.show', ['invoice' => $invoiceId]);
})->name('extensions.gateways.famgateway.callback');
