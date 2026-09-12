<?php

use Illuminate\Support\Facades\Route;
use Paymenter\Extensions\Gateways\FamGateway\FamGateway;

Route::post(
    '/extensions/gateways/famgateway/webhook/{invoiceId}',
    [FamGateway::class, 'webhook']
)->name('extensions.gateways.famgateway.webhook');

Route::get('/extensions/gateways/famgateway/callback/{invoiceId}', function ($invoiceId) {
    return redirect()->route('invoices.show', ['invoice' => $invoiceId]);
})->name('extensions.gateways.famgateway.callback');
