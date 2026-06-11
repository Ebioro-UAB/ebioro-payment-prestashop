# Ebioro Payments for PrestaShop 8

Accept USDC payments through the Ebioro hosted checkout. At checkout the customer
is redirected to the Ebioro-hosted payment page; the order is confirmed
asynchronously by webhook. Mirrors the behaviour of the Ebioro WooCommerce plugin.

- **Target:** PrestaShop 8.0+ (PHP 8)
- **Flow:** hosted checkout → redirect → webhook settlement → order status update

## Module layout

```
ebioropayment.php                     Main module (PaymentModule): install, config, hooks
classes/EbioroApiHandler.php          API client — HMAC signing, create payment, webhook verify
controllers/front/redirect.php        Creates the order + Ebioro payment, redirects to hosted page
controllers/front/webhook.php         Verifies X-WEBHOOK-AUTH, maps status → order state
views/templates/hook/payment_return.tpl   Confirmation message
docker/docker-compose.yml             Local PrestaShop 8 + MySQL for testing
```

## Configure

Back Office → **Modules** → install **Ebioro Payments** → **Configure**:

- **API Key** — your Ebioro public key (`pk_...`)
- **API Secret** — your Ebioro secret key (`sk_...`)
- **Test mode** — on = `test-merchant.ebioro.com`, off = `merchant-api.ebioro.com`
- **Checkout locale** — `en`, `es`, …

## Local test environment (Docker)

No PrestaShop install needed — this brings up a throwaway shop with the module
mounted live.

```bash
cd ~/ebioro/merchant/ebioro-payment-prestashop
docker compose -f docker/docker-compose.yml up -d
# First boot takes a few minutes while PrestaShop installs.
```

- Storefront: <http://localhost:8080>
- Back Office: <http://localhost:8080/admin-dev> — `admin@ebioro.test` / `ebioro1234`

Then: install the module, enter your **test** API credentials, enable USDC as a
shop currency, and place a test order. The module source is mounted into the
container, so code edits show up immediately — after changing `install()` or
hooks, reset the module from the Back Office.

> Tip: to reset cleanly, `docker compose -f docker/docker-compose.yml down -v`
> wipes the DB and shop so the next `up` reinstalls from scratch.

## Testing the webhook

Ebioro needs a public HTTPS URL to deliver webhooks. For a real end-to-end test,
expose the shop with a tunnel and set PrestaShop's domain to it:

```bash
cloudflared tunnel --url http://localhost:8080    # or: ngrok http 8080
```

To exercise the webhook handler directly (no tunnel), POST a signed payload —
the signature is `HMAC-SHA256(rawBody, API_SECRET)` over the **exact** body:

```bash
SECRET='sk_test_your_secret'
BODY='{"data":{"type":"transaction_updated","status":"paid","settlement_status":"paid","metadata":{"order_id":1}}}'
SIG=$(printf '%s' "$BODY" | openssl dgst -sha256 -hmac "$SECRET" | sed 's/^.* //')
curl -i -X POST 'http://localhost:8080/index.php?fc=module&module=ebioropayment&controller=webhook' \
  -H 'Content-Type: application/json' \
  -H "X-WEBHOOK-AUTH: $SIG" \
  --data-raw "$BODY"
```

A valid signature → `200 OK` and the order (id `1` here) moves to **Payment
accepted**. A wrong/absent signature → `401 Invalid signature`.

## Status mapping

| Ebioro event | PrestaShop order state |
|---|---|
| `transaction_created` | Awaiting Ebioro payment (set at redirect) |
| `status: paid` | Payment accepted |
| `status: underpaid` | stays awaiting + note |
| `status: failed` / `expired` / `canceled`, or `transaction_failed` | Canceled |

`settlement_status` (`processing` → `paid`) is recorded as order notes for
reconciliation.

## Notes

- Refunds are issued from the Ebioro enterprise portal, not the API or this
  module (non-custodial refunds require the merchant's signing session).
- The signed request body and the transmitted body use identical `json_encode`
  flags so the signature always matches what is sent.
