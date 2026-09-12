# FamGateway — Paymenter Gateway Extension

A Paymenter payment gateway extension for **FamGateway** (zero-fee P2P UPI gateway).

## Install

1. Copy the `FamGateway` folder into `extensions/Gateways/` in your Paymenter installation.
2. Enable the "FamGateway" gateway from the Paymenter admin panel.
3. Enter your FamGateway **API Key** (`sk_live_...`) in the gateway settings.

## How it works

- `pay()` calls FamGateway's `createOrder` API to get a `checkout_url` / `qr_url`, then
  renders `views/pay.blade.php`, which auto-redirects the customer to the hosted checkout
  (and shows the UPI QR as a fallback).
- The invoice ID is embedded directly in the per-order `redirect_url` and `webhook_url`
  (`/extensions/gateways/famgateway/webhook/{invoiceId}`), since FamGateway's API has no
  order-metadata field to round-trip an invoice ID the way Razorpay's `notes` does.
- `webhook()` verifies the `X-FamGateway-Signature` header with HMAC-SHA256 (timing-safe
  `hash_equals`) using your API key as the secret, exactly as in the official SDK, then marks
  the invoice paid via `ExtensionHelper::addPayment()` on a `payment.success` / `success` event.
- Only INR invoices are supported (FamGateway is UPI-only) — other currencies show a
  friendly error view, same as the Razorpay extension.

## Files

- `FamGateway.php` — the Paymenter `Gateway` extension class (config, pay, webhook).
- `Includes/FamGatewayClient.php` — your original SDK logic, renamed from `FamGateway` to
  `FamGatewayClient` internally so it doesn't collide with the extension class name.
- `routes/web.php` — webhook + redirect callback routes.
- `views/pay.blade.php`, `views/error.blade.php` — checkout and error pages.

## Note

FamGateway's `createOrder`/`getOrderStatus` send the API key as a URL query parameter
(inherited as-is from your original SDK). That means the key can end up in web server
access logs or any proxy/CDN request logs in front of `famgateway.in`. Consider asking
FamGateway support whether they support the key via an `Authorization` header instead —
worth tightening if you plan to distribute this extension publicly.
