<?php

namespace Paymenter\Extensions\Gateways\FamGateway\Includes;

/**
 * FamGateway PHP SDK (ported from the official famgateway-sdk)
 * Renamed to FamGatewayClient inside this extension to avoid clashing
 * with the Paymenter extension class, which is also named FamGateway.
 */
class FamGatewayClient
{
    private $apiKey;
    private $baseUrl = 'https://famgateway.in';

    public function __construct($apiKey)
    {
        if (empty($apiKey) || !is_string($apiKey)) {
            throw new \InvalidArgumentException('A valid FamGateway API Key string is required.');
        }
        $this->apiKey = trim($apiKey);
    }

    /**
     * Internal HTTP GET request helper with robust cURL support and stream fallback.
     * Works seamlessly even on shared hosting where allow_url_fopen is disabled.
     */
    private function makeRequest($url)
    {
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 15,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_USERAGENT => 'FamGateway-PHP-SDK/2.0',
                CURLOPT_HTTPHEADER => ['Accept: application/json'],
            ]);
            $response = curl_exec($ch);
            curl_close($ch);
            if ($response !== false) {
                return $response;
            }
        }

        // Fallback to file_get_contents if cURL is not available
        $ctx = stream_context_create([
            'http' => [
                'timeout' => 15,
                'header' => "User-Agent: FamGateway-PHP-SDK/2.0\r\nAccept: application/json\r\n",
            ],
        ]);
        $response = @file_get_contents($url, false, $ctx);
        if ($response === false) {
            throw new \Exception('FamGateway Error: Failed to connect to API server.');
        }
        return $response;
    }

    /**
     * Create an order and return raw API response data without redirecting.
     * Used by the extension so it can render its own checkout/redirect view.
     *
     * @param float|int|string $amount The amount to charge (e.g., 499.00)
     * @param array $params Optional: ['redirect_url' => '', 'webhook_url' => '', 'customer_name' => '', 'customer_email' => '', 'customer_phone' => '']
     * @return array Order data including order_id, checkout_url, qr_url, upi_intent
     * @throws \Exception On API or network error
     */
    public function createOrder($amount, $params = [])
    {
        $query = [
            'api_key' => $this->apiKey,
            'amount' => (string) $amount,
        ];
        if (!empty($params['redirect_url'])) {
            $query['redirect_url'] = $params['redirect_url'];
        }
        if (!empty($params['webhook_url'])) {
            $query['webhook_url'] = $params['webhook_url'];
        }
        if (!empty($params['customer_name'])) {
            $query['customer_name'] = $params['customer_name'];
        }
        if (!empty($params['customer_email'])) {
            $query['customer_email'] = $params['customer_email'];
        }
        if (!empty($params['customer_phone'])) {
            $query['customer_phone'] = $params['customer_phone'];
        }

        $url = $this->baseUrl . '/api/qr.php?' . http_build_query($query);
        $response = $this->makeRequest($url);

        $data = json_decode($response, true);
        if (isset($data['status']) && $data['status'] === 'success') {
            return $data['data'];
        }
        throw new \Exception('FamGateway Error: ' . ($data['message'] ?? 'Unknown API error'));
    }

    /**
     * Check order status from FamGateway server.
     *
     * @param string $orderId The order ID (e.g. fg_A1B2C3D4)
     * @return array Status array containing status, order_id, amount, utr, etc.
     * @throws \Exception On API or network error
     */
    public function getOrderStatus($orderId)
    {
        if (empty($orderId)) {
            throw new \InvalidArgumentException('Order ID is required to fetch status.');
        }
        $query = [
            'api_key' => $this->apiKey,
            'order_id' => trim($orderId),
        ];
        $url = $this->baseUrl . '/api/verify-order.php?' . http_build_query($query);
        $response = $this->makeRequest($url);
        return json_decode($response, true);
    }

    /**
     * Verify the webhook signature to ensure the request is authentically from FamGateway.
     * Uses timing-safe hash_equals() to prevent timing attack vulnerabilities.
     *
     * @param string $rawPostData The raw POST body (file_get_contents('php://input'))
     * @param string $signature The HTTP_X_FAMGATEWAY_SIGNATURE header
     * @return array|false Returns the decoded JSON payload if valid, or false if invalid.
     */
    public function verifyWebhook($rawPostData, $signature)
    {
        if (!$rawPostData || !$signature) {
            return false;
        }

        $expectedSignature = hash_hmac('sha256', $rawPostData, $this->apiKey);

        if (hash_equals($expectedSignature, trim($signature))) {
            return json_decode($rawPostData, true);
        }

        return false;
    }
}
