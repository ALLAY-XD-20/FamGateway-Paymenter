<?php

namespace Paymenter\Extensions\Gateways\FamGateway;

use Illuminate\Http\Request;
use App\Helpers\ExtensionHelper;
use App\Classes\Extension\Gateway;
use Illuminate\Support\Facades\View;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Paymenter\Extensions\Gateways\FamGateway\Includes\FamGatewayClient;

// How long a generated FamGateway order is tracked for the "return from
// checkout" fallback verification below. After this, verifyPaymentStatus()
// will no longer be able to look up the order_id for that invoice — it
// does NOT affect the webhook, which keeps working for late arrivals.
const FAMGATEWAY_ORDER_TTL_SECONDS = 3600; // 1 hour

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

            // Remember which FamGateway order_id belongs to this invoice, for
            // 1 hour, so that when the customer is redirected back we can
            // actively re-check payment status instead of relying solely on
            // the webhook (webhooks can be delayed, dropped, or blocked by a
            // firewall — that mismatch is what causes "payment successful
            // but status not updated").
            if (!empty($order['order_id'])) {
                Cache::put(
                    'famgateway_order:' . $invoice->id,
                    $order['order_id'],
                    now()->addSeconds(FAMGATEWAY_ORDER_TTL_SECONDS)
                );
            }

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
            if (!$this->invoiceAlreadyPaid($invoiceId)) {
                ExtensionHelper::addPayment($invoiceId, 'FamGateway', $amount, null, $transactionId);
            }
            // Whether the webhook or the redirect fallback got there first,
            // the order is settled — no need to verify it again later.
            Cache::forget('famgateway_order:' . $invoiceId);
        }

        return response('Webhook received and processed successfully');
    }

    /**
     * Called from the callback route when the customer is redirected back
     * from FamGateway's hosted checkout. This is a fallback safety net for
     * when the webhook hasn't arrived yet (or never arrives): it actively
     * asks FamGateway for the order's real status and marks the invoice
     * paid if needed, instead of just trusting that the webhook already did.
     */
    public function verifyPaymentStatus($invoiceId)
    {
        if ($this->invoiceAlreadyPaid($invoiceId)) {
            return;
        }

        $orderId = Cache::get('famgateway_order:' . $invoiceId);
        if (empty($orderId)) {
            // Either the order is older than the 1 hour tracking window, or
            // pay() was never reached for this invoice. Nothing to verify.
            return;
        }

        $apiKey = $this->config('api_key');

        try {
            $client = new FamGatewayClient($apiKey);
            $status = $client->getOrderStatus($orderId);
        } catch (\Exception $e) {
            Log::warning('FamGateway: status check failed for invoice ' . $invoiceId . ': ' . $e->getMessage());
            return;
        }

        $state = strtolower($status['status'] ?? '');

        if (in_array($state, ['success', 'paid', 'completed'], true)) {
            $amount = $status['amount'] ?? null;
            $transactionId = $status['transaction_id'] ?? ($status['utr'] ?? null);
            ExtensionHelper::addPayment($invoiceId, 'FamGateway', $amount, null, $transactionId);
            Cache::forget('famgateway_order:' . $invoiceId);
        }
    }

    /**
     * NOTE: adjust this to match your Paymenter version's Invoice model if
     * needed — this checks the invoice's `status` column for a paid value
     * before crediting it again, so a webhook that arrives late right after
     * the redirect fallback already ran doesn't double-add the payment.
     */
    protected function invoiceAlreadyPaid($invoiceId): bool
    {
        try {
            $invoice = \App\Models\Invoice::find($invoiceId);
            if (!$invoice) {
                return false;
            }
            return in_array(strtolower((string) $invoice->status), ['paid', 'completed'], true);
        } catch (\Exception $e) {
            // If we can't determine the status, don't block crediting —
            // Paymenter's own addPayment/invoice logic is the source of truth.
            return false;
        }
    }
}
