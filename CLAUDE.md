# ebioro-payment-prestashop

PrestaShop 8 payment module for Ebioro hosted checkout (public repository). Mirrors the WooCommerce plugin: the customer is redirected to the Ebioro-hosted payment page, and a signed webhook confirms settlement and moves the order to "Payment accepted". It is a thin client of the Ebioro merchant public API — same HMAC signing, hosted checkout, and webhook contract as `ebioro-payment-woocommerce` and `ebioro-odoo`; cross-check those two before treating a bug as PrestaShop-specific.

## Architecture
- `ebioropayment.php` — `PaymentModule`: install (creates the custom "Awaiting Ebioro payment" order state), config form, hooks.
- `classes/EbioroApiHandler.php` — API client: HMAC signing, create payment, webhook verification.
- `controllers/front/redirect.php` — creates the order in the awaiting state, opens the Ebioro payment with the order id in metadata, redirects.
- `controllers/front/webhook.php` — verifies `X-WEBHOOK-AUTH` (HMAC-SHA256 over the exact raw body), maps status → order state.
- Test mode switches the API base URL; keys are entered in the Back Office module config.

## Dev commands
- `docker compose -f docker/docker-compose.yml up -d` — throwaway PrestaShop 8 + MySQL with the module mounted live. `down -v` wipes it for a clean reinstall.
- After changing `install()` or hooks, **reset the module** from the Back Office — mounted source edits do not re-run install.
- Webhooks need a public HTTPS URL: tunnel the shop (`cloudflared tunnel --url http://localhost:8080`) and set the shop domain to it, or POST a signed payload directly (see README).

## Gotchas
- **Redirect with `shortUrl`; fall back to `hostedUrl` only when absent.** `hostedUrl` carries an auth token in the query string. Same rule as the other two plugins.
- **The signature covers the exact raw body.** JSON re-encoding (flags, whitespace) changes the bytes and breaks verification — the same `json_encode` flags are used for signing and for the request body; keep them identical.
- **Timestamp is UTC seconds and must fall within the server's ±5 minute window.** A container with a drifted clock fails every request with 401.
- The custom order state is created on install and its id stored in `EBIORO_OS_AWAITING`; if it is deleted in the Back Office, reinstall/reset the module rather than hard-coding a state id.
- The order is created before the redirect (`validateOrder` in the awaiting state). An abandoned checkout leaves an awaiting order — expected; the webhook or expiry resolves it.
- Only the webhook changes order state. Never let the return/confirmation page do it.

<!-- BEGIN ebioro-non-negotiables v2 — master: Ebioro-UAB/documentation -->
## Ebioro non-negotiables

- **GitHub text hygiene — the KU corridor country is never named.** In any
  GitHub-visible text (commit messages, PR titles/bodies, reviews, issues,
  branch names, release notes, code/spec comments) write `KU` — never the
  country's name, demonym, or capital. The ISO code `CU` as functional data
  (string literals, catalog entries, `=== 'CU'` checks) and full-country
  datasets (countries.json, i18n locales) are fine. End-user UI strings may
  carry the real name; keep those literals minimal. Scrub old text on touch.
- **Never merge or push to `main`, never tag a production release, never deploy.**
  Open the PR and stop. Merges and deploys are human-only — no exception for
  "the review passed" or "it's just a patch bump". Never delete `main`,
  `master`, or `development`.
- **Never commit `.env` or any file containing a secret.** `.gitignore` covers
  `.env*` with `.env.example` as the only tracked variant. A secret that lands
  in git is leaked even after the file is removed — rotate it.
- **No AI attribution in git or GitHub text.** No `Co-Authored-By:` lines, no
  "Generated with …" footers in commits, PR bodies, or issues.
- **Error handling: `neverthrow` Result types. Never `try/catch`.**
- **TypeORM migrations: snake_case column identifiers only.** Quoting
  `"customerId"` preserves camelCase; TypeORM then queries `customer_id` and
  crashes at runtime. `build` does not catch it. This has cost two fix
  migrations already.
- **Follow the existing flow in code, not design docs or mockups.** Find the
  nearest equivalent already implemented and match it. Design notes are
  proposals.
- **Money paths**: integer stroops/cents, never floats. Idempotency keys on
  anything that moves money. Stellar sequence numbers fetched fresh. Never log
  or expose a signing key or an API secret.
- **Ebioro never holds keys for customer funds.** Before creating any key or
  account, ask whose funds it will hold. If a customer's, it cannot be a key
  Ebioro can use alone.
- **User-facing copy hides blockchain jargon.** "Payment reference", not
  "transaction hash". "Settled", not "confirmed on ledger N". "Network fee",
  not "XLM base fee". No signing-vendor names. It should read like a bank app,
  not a block explorer.
- **No regulatory claims** in user-facing text — not "licensed", "registered",
  "authorised", "MiCAR-compliant", or any variant. Route legal-sounding copy
  through Ebioro before merging.
- **Soft-delete only** (`deletedAt`). Never hard-delete.
- **Never skip pre-commit hooks** (`--no-verify`). Flag new dependencies in the
  PR description.
- **Never paste production data, customer PII, credentials, or KYC/AML content
  into an AI tool.** Anonymised or synthetic only.
<!-- END ebioro-non-negotiables v2 -->
