<?php

namespace Paymenter\Extensions\Gateways\FamGateway;

use Illuminate\Http\Request;
use App\Helpers\ExtensionHelper;
use App\Classes\Extension\Gateway;
use Illuminate\Support\Facades\View;
use Paymenter\Extensions\Gateways\FamGateway\Includes\FamGatewayClient;

class FamGateway extends Gateway
{
    public function boot()
    {
        require __DIR__ . '/routes/web.php';
        View::addNamespace('extensions.gateways.famgateway', __DIR__ . '/views');
    }

    public function getConfig($values = [])
    {
        return [
            [
                'name' => 'api_key',
                'label' => 'API Key',
                'description' => 'Your FamGateway Secret API Key (sk_live_...) from https://famgateway.in/dashboard.php',
                'type' => 'text',
                'required' => true,
            ],
        ];
    }

    public function pay($invoice, $total)
    {
        if ($invoice->currency_code !== 'INR') {
            return view('extensions.gateways.famgateway::error', [
                'error' => 'The product currency code must be "INR" to make payments with FamGateway!',
            ]);
        }

        $apiKey = $this->config('api_key');
        $client = new FamGatewayClient($apiKey);

        $redirectUrl = route('extensions.gateways.famgateway.callback', ['invoiceId' => $invoice->id]);
        $webhookUrl = route('extensions.gateways.famgateway.webhook', ['invoiceId' => $invoice->id]);

        $params = [
            'redirect_url' => $redirectUrl,
            'webhook_url' => $webhookUrl,
        ];

        // Best-effort customer details; skipped silently if not available on the invoice model
        try {
            if (!empty($invoice->user)) {
                if (!empty($invoice->user->name)) {
                    $params['customer_name'] = $invoice->user->name;
                }
                if (!empty($invoice->user->email)) {
                    $params['customer_email'] = $invoice->user->email;
                }
            }
        } catch (\Exception $e) {
            // Ignore, customer details are optional
        }

        try {
            $order = $client->createOrder($total, $params);

            return view('extensions.gateways.famgateway::pay', [
                'checkoutUrl' => $order['checkout_url'] ?? null,
                'qrUrl' => $order['qr_url'] ?? null,
                'upiIntent' => $order['upi_intent'] ?? null,
                'orderId' => $order['order_id'] ?? null,
                'invoiceId' => $invoice->id,
            ]);
        } catch (\Exception $e) {
            return view('extensions.gateways.famgateway::error', [
                'error' => 'Failed to create order: ' . $e->getMessage(),
            ]);
        }
    }

    public function webhook(Request $request, $invoiceId)
    {
        $content = $request->getContent();
        $signature = $request->header('X-FamGateway-Signature');
        $apiKey = $this->config('api_key');

        $client = new FamGatewayClient($apiKey);
        $data = $client->verifyWebhook($content, $signature);

        if ($data === false) {
            return response('Signature verification failed', 401);
        }

        $event = $data['event'] ?? ($data['status'] ?? null);
        $amount = $data['amount'] ?? null;
        $transactionId = $data['transaction_id'] ?? ($data['utr'] ?? null);

        if ($event === 'payment.success' || $event === 'success') {
            ExtensionHelper::addPayment($invoiceId, 'FamGateway', $amount, null, $transactionId);
        }

        return response('Webhook received and processed successfully');
    }
}
