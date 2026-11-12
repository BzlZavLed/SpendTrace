# SpendTrace

A working personal receipt ledger built with Laravel 13, Inertia 3, Vue 3 + TypeScript, and PostgreSQL. Capture multiple photos of one receipt, save originals privately, extract asynchronously, and correct only what needs attention. Apple Wallet via iPhone Shortcuts can submit future purchase observations and match them to receipts. This is not access to Wallet history or bank reconciliation.

## Requirements

- PHP **8.4+** for the locked dependencies, Composer 2, and extensions `pdo_pgsql`, `fileinfo`, `mbstring`, `openssl`, `xml`, `curl`, `ctype`, `tokenizer`, `session`, and `dom`. The `gd` extension enables rotated reading views for extraction and is required by image tests; tests also use `pdo_sqlite`. Queue workers need `pcntl` for enforced timeouts on Unix.
- Node **22.12+** (use a supported LTS release) and npm. Commit/install the included lockfiles.
- PostgreSQL **16+** for a new installation. The provided Compose service uses PostgreSQL 16; Docker is optional if you already run PostgreSQL.
- PHP must be 64-bit. No external image conversion binary is required.

## Local setup

From this repository:

```sh
composer install
npm ci
cp .env.example .env
php artisan key:generate
docker compose up -d --wait
php artisan migrate
npm run build
composer run dev
```

Open **http://localhost:8000/register** and create your own account. There are no seeded credentials or invented purchases. If `.env` already exists, preserve its `APP_KEY` and configure its database values instead of copying over it.

`composer run dev` starts the upload-capable PHP server, database queue worker, and Vite together. For separate terminals:

```sh
composer serve
php artisan queue:work --tries=1 --timeout=120 --sleep=1
npm run dev
```

After `npm run build`, Vite is optional: run just `composer serve` and the queue worker. `composer serve` sets `upload_max_filesize=10M`, `post_max_size=65M`, `max_file_uploads=6`, and `memory_limit=512M`. A plain `php artisan serve` also works if these values are configured in your PHP ini. A reverse proxy must allow a 65 MB request body. Keep the worker timeout (120 seconds) below `DB_QUEUE_RETRY_AFTER` (180 seconds).

The Compose database is bound to localhost port **54329**, with a named volume. Its password is for local development only. For an existing PostgreSQL database, create a database/user and change `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, and `DB_PASSWORD` in `.env`; then run `php artisan migrate`. Do not use `migrate:fresh` against a real ledger.

## Configuration

| Variable | Default / purpose |
| --- | --- |
| `APP_KEY` | Generated locally; keep private and back up securely. |
| `WALLET_PUBLIC_URL` | Optional reachable HTTPS origin displayed in Wallet setup; falls back to APP_URL. No credentials. |
| `APP_URL` | `http://localhost:8000`; set the HTTPS URL for phone testing. |
| `APP_DEBUG` | `false`; avoids exposing financial data in error pages. |
| `DB_CONNECTION` | `pgsql`. |
| `DB_HOST`, `DB_PORT` | `127.0.0.1`, `54329` for Compose. |
| `DB_DATABASE`, `DB_USERNAME` | `spendtrace`. |
| `DB_PASSWORD` | Compose-only development password; change outside local use. |
| `QUEUE_CONNECTION` | `database`; never `sync` for normal receipt processing. |
| `DB_QUEUE_RETRY_AFTER` | `180`; must exceed worker timeout. |
| `FILESYSTEM_DISK` | `local`; originals explicitly use the private local disk. |
| `SESSION_DRIVER`, `SESSION_ENCRYPT` | `database`, `true`. |
| `SESSION_SECURE_COOKIE` | `false` for localhost; **true** for HTTPS. |
| `RECEIPT_PROVIDER` | `fake` or `openai`. Unknown values fail rather than falling back silently. |
| `OPENAI_API_KEY` | Server-only API key, required for `openai`. |
| `OPENAI_MODEL` | `gpt-4.1-mini`; configurable image-capable model supporting strict structured output. |
| `BOOST_ENABLED` | `false`; development assistant/browser logging is disabled. |

Display timezone defaults to **America/New_York** in `config/spendtrace.php`. Upload timestamps are stored separately from printed purchase dates and times. Printed local time does not imply a known timezone. Supported currencies for this milestone: USD, CAD, EUR, GBP (2 decimals), JPY (0), and KWD (3). Other currencies remain unknown with a review reason. No foreign-exchange conversion is performed.

After changing provider settings:

```sh
php artisan config:clear
php artisan queue:restart
```

Restart the worker process if it exits; a production process supervisor should do that automatically.

## Fake and real extraction

**Fake mode is deterministic and deliberately conservative:** it reads no image, creates no invented purchase details, and returns unknown values plus an explicit fake-result review reason. The banner and receipt detail identify fake mode. Upload a real photo and fill the fields manually to exercise the complete ledger locally. Automated tests supply known structured fixtures for successful extraction and arithmetic cases.

For real extraction, set `RECEIPT_PROVIDER=openai`, supply `OPENAI_API_KEY`, restart the worker, and scan or retry a receipt. **Receipt images are sent to OpenAI for processing.** The adapter uses the Responses API with base64 image inputs, strict JSON schema output, and `store: false`. That flag does not establish a zero-retention agreement; the configured provider's account/data policies still apply. Credentials never reach the browser. No live API call is made by tests.

Receipt text is treated as untrusted evidence, never as instructions. Unknown amounts, dates, currencies, card suffixes, or items remain null. The app validates structured output before using it; incomplete responses, refusals, malformed output, and transport failures produce a safe failure state. It does not persist complete OCR text or full provider response bodies. Valid schema-filtered extraction history is retained separately from edits; long card-like numeric text in extraction strings is redacted.

Extraction version `receipt-v3` stores printed total and line amounts as exact decimal strings independently of currency. Unknown or unsupported currency never hides a readable amount. Decimal separators are normalized to a period only when their interpretation is clear; ambiguous numbers stay unknown. The app derives integer minor units only for known supported currencies, uses exact scaled-integer arithmetic for receipt checks, and excludes unknown-currency receipts from currency-based spending totals. Printed values can be edited/saved before currency is identified; selecting or changing currency never rescales them. No store, receipt ID, or default currency is used to repair future records.

The non-destructive migration backfills decimal values only for older records with known currency precision. Older unknown-currency records need re-extraction or explicit currency identification; the migration does not guess their decimal scale. Up to six printed decimal places and nine whole-number digits are supported.

Extraction also preserves abbreviated sale descriptions, considers department headings when suggesting categories, records a printed item count when available, and flags missing item lines. Printed minute-only times are accepted and normalized to `HH:MM:00` for storage. With GD enabled, landscape photos up to 12 megapixels are sent with an additional clockwise-rotated reading view; originals remain unchanged. The additional view increases image-token usage. Larger images or installations without GD use the original only. All views are explicitly identified as the same receipt to avoid duplicate rows.

Source references: [Laravel 13 prerequisites](https://laravel.com/framework/docs/releases), [Inertia 3 setup](https://inertiajs.com/docs/v3/installation/client-side-setup), [OpenAI image inputs](https://developers.openai.com/api/docs/guides/images-vision), and [structured outputs](https://developers.openai.com/api/docs/guides/structured-outputs).

## Data and reliability

- An authenticated upload accepts 1–6 validated JPEG/PNG/WebP images, at most 10 MB and 50 megapixels each. HEIC/HEIF gets actionable conversion instructions. All files are validated before any are saved.
- Originals live under `storage/app/private/receipts/`. Do **not** expose this directory or link it into `public/`. Every image request verifies ownership and returns no-store headers. Eloquent storage paths and hashes are hidden from page props.
- Upload creates an unreconciled transaction with nullable fields and persists originals before dispatching a durable database job. Closing the browser after upload does not stop the queue.
- Exact image hashes are unique **per user**; identical uploads reopen the existing receipt. Partial overlap is rejected with guidance, avoiding accidental extra transactions. Resized/recompressed images are not exact matches.
- Extraction jobs serialize on PostgreSQL owner and receipt row locks, check generation, and use the unique receipt-to-transaction relationship. Re-delivery cannot create another transaction. A retry increments the generation; obsolete jobs cannot replace newer results. One worker attempt is made; failures can be retried explicitly from receipt details.
- A worker killed before it marks failure can leave a receipt queued. Start the worker again or use Retry; the generation guard makes stale queued deliveries harmless. `php artisan queue:failed` inspects framework-level failures without displaying financial content.
- Extraction runs preserve each valid structured result separately. Edits track corrected fields; changing a money value protects its currency too. Marking a transaction reviewed protects all reviewed fields/items. Reprocessing updates only uncorrected fields and returns the receipt to Needs review. A revision check prevents overwriting changes that arrived while an edit form was open.
- Account suggestions use physical or device-specific last-four identifiers. Ambiguous suffixes require a choice; one match remains a suggestion to confirm. Supported institution labels include Bank of America, Capital One, Chime, and Synchrony; use your own product nicknames.
- Likely duplicates compare normalized merchant, purchase date, amount, currency, and account when known. Receipt-to-receipt duplicates do not merge automatically. Wallet matching follows the stricter rules below. Open the other receipt and optionally exclude one record from totals; its evidence stays available.
- Summaries sum transaction totals, **never items**, per currency, from the start of the current month through today in America/New_York. Future purchase dates are not included. Known but unreviewed amounts are included; missing dates, currencies, or totals are excluded. The unresolved count spans the ledger, so incomplete records cannot disappear behind an upload-month assumption. Excluded records and Wallet records attached to a receipt purchase are omitted. Refunds can be recorded as negative amounts.
- Arithmetic checks use `subtotal + tax + tip − additional discount` only when the provider confirms that interpretation of the layout. Discounts already reflected in subtotal must not be subtracted twice. Unknown components and unsupported layouts are review reasons. Items are not assumed complete or automatically summed to replace the receipt total.
- Source observations retain type, source ID, payload, and observed-at evidence for receipts and Wallet events, with extension points for future bank alerts and imported transactions. Wallet observations are implemented below; bank alerts and imports are not implemented. A receipt never marks a purchase bank-reconciled.

Extraction failures log `Receipt extraction failed` with receipt/attempt IDs, stage, exception class/source, safe diagnostic, and (when available) HTTP status, OpenAI request ID, recognized error code, or invalid field names. Inspect with `tail -f storage/logs/laravel.log` and retry the receipt after restarting workers to load code changes. Earlier failures cannot be diagnosed retroactively. Application code does not log receipt images, text, suffixes, API keys, raw exception messages, or provider bodies. Avoid adding request logging or debug tooling to financial routes. Back up both PostgreSQL and private storage together, protect backups, and restrict file permissions. Do not upload receipts containing unmasked full card numbers, CVVs, or banking credentials; originals are preserved as supplied, not redacted. The application has no fields for those values.

## Verification

```sh
php artisan test --compact
node --experimental-strip-types --test tests/Frontend/*.test.mjs
npm run build
vendor/bin/pint --format agent
```

The Wallet implementation is verified against an isolated PostgreSQL database, including real concurrent PHP processes issuing duplicate events and racing receipt extraction with matching. Backend tests cover ownership, account isolation, credential lifecycle, payload validation, exact amounts, matching in both orders, correction preservation, summary totals, and undo. Frontend formatting tests, TypeScript checks, and the production build are also run. No live iPhone Wallet verification has been performed. The earlier optical-receipt extraction test is an individual example, not an OCR accuracy guarantee.

The default test suite uses isolated in-memory SQLite. To run against PostgreSQL, first create a **separate disposable test database**:

```sh
docker compose exec postgres createdb -U spendtrace spendtrace_test
DB_CONNECTION=pgsql DB_HOST=127.0.0.1 DB_PORT=54329 DB_DATABASE=spendtrace_test DB_USERNAME=spendtrace DB_PASSWORD=spendtrace-local-only php artisan test --compact
```

Tests reset the selected test database. Never point them at your personal ledger. Coverage includes authentication/ownership, file validation, private images, successful and failed extraction, repeated delivery, corrections, unknown fields, arithmetic, duplicate uploads and purchases, ambiguous card suffixes, exact money, summaries, account identifiers, and mocked real-provider requests.

## iPhone testing checklist

1. Run the server and queue worker; build assets with `npm run build` and stop Vite for phone testing. Remove a stale `public/hot` file if Vite was interrupted rather than shut down normally.
2. Serve the app through a trusted **HTTPS** development hostname/reverse proxy or authenticated HTTPS tunnel to local port 8000. Set `APP_URL` to that URL and `SESSION_SECURE_COOKIE=true`; run `php artisan config:clear`. If the proxy terminates TLS, configure Laravel's trusted proxies narrowly for that proxy. Do not indiscriminately trust forwarded headers on an exposed server.
3. An iPhone's `localhost` is the phone, not your Mac. A Mac LAN IP over HTTP is generally **not a secure context**. Inertia history encryption and browser camera APIs need a secure context; desktop localhost is an exception, a phone LAN IP is not. Open camera uses `getUserMedia` for a live preview and a selectable camera, with a rear-camera preference. The separate Use device camera app option uses a native file input with `capture="environment"`; browser/OS support determines how that fallback opens. The separate Choose photos button always offers the normal picker.
4. Register/sign in in Safari. Confirm navigation, labels, 16 px inputs (no focus zoom), and buttons fit portrait width. Check landscape and large text settings.
5. Tap Scan receipt → Open camera. Allow permission, confirm a live preview, capture multiple photos, and switch cameras. Confirm that closing the preview, changing tabs, or leaving the page releases the camera. Deny permission and confirm actionable guidance and the photo picker remain available. On a Mac, enable Apple Continuity Camera, keep the iPhone locked/nearby (or connect by USB and trust the Mac), then select it in the Camera menu. Refresh cameras after connecting it. Only devices exposed by macOS/browser can be selected; the site cannot pair a nearby phone. Verify readable text in the captured JPEG before uploading. Use device camera app remains available for native phone capture.
6. Select 2–6 images of one receipt; check previews, remove a photo, and upload. Verify progress, saved notice, private originals, and the processing state. Close Safari, keep the worker running, reopen, and verify the result.
7. Try HEIC: confirm the conversion message. Export a JPEG using Files → Quick Actions → Convert Image where available, or another trusted local converter. For new photos choose Settings → Camera → Formats → Most Compatible. Changing the camera setting does not convert old images.
8. Try an oversized image, unsupported file, and the exact same photo twice. Confirm helpful errors or reopening of the saved receipt, with no duplicate ledger record.
9. With real mode enabled, check merchant/date/total/currency/items against a printed receipt including tax, discount, and tip. Verify a failure preserves originals and Retry works after configuration is corrected.
10. Correct a field, save, reprocess, and confirm the correction survives. Add two accounts with the same suffix and verify ambiguity. Review/exclude a likely duplicate. Confirm reviewed entries remain unreconciled.
11. Sign out, sign into another account, and verify the first account's receipt/image URLs are inaccessible. After logout, back navigation must not reveal cached financial page data.

## Current limits

No live bank, Wallet history, SMS/email, voice, subscriptions, or household integrations. Wallet capture is limited to events that a configured iPhone automation successfully submits. No automated HEIC conversion, OCR accuracy guarantee, exchange rates, or visual duplicate-image comparison. No password-reset/email-verification flow or account deletion UI in this milestone. For a public deployment, add those lifecycle flows, email delivery, an HTTPS web server/process supervisor, storage backups, and registration controls appropriate to your deployment.

The real provider is tested with mocked HTTP and one live optical-receipt example; other receipt layouts still need evaluation with your own receipts. This environment has no browser-control connection, so Safari/camera and visual rendering checks remain manual. The repository includes the exact iPhone checklist above rather than claiming those device checks passed.

## Apple Wallet via Shortcuts

Run `php artisan migrate`, build assets, and restart the queue worker to load this version. In SpendTrace, open **Wallet**, create one connection for your whole Wallet, add card keys mapped to your owned financial accounts, optionally configure a default currency, and enable submissions. All configured cards share the same endpoint and token. Create the dedicated token and copy it into your shortcut. The token appears once in the creation response, never in saved page props. Replacing or revoking it invalidates it immediately. Disabling rejects submissions but preserves data; deleting soft-deletes the connection and revokes its token while retaining source history. Card mappings can change for future events; each accepted event permanently records its resolved user and account, so edits cannot redirect queued work or historical purchases. Existing single-card shortcuts without `card_key` keep their original account binding.

Use a reachable HTTPS deployment or tunnel, set `WALLET_PUBLIC_URL=https://your-spendtrace.example`, and clear cached configuration. This variable only controls the displayed endpoint; it does not establish hosting, TLS, or a tunnel. Localhost on an iPhone is the phone, not the Mac. Keep the Mac, web server, database, and worker running during local development. Follow the trusted-proxy and HTTPS setup above. Do not send a token over a public HTTP connection. No native iOS app or FinanceKit entitlement is needed for this HTTP approach.

### Request contract

`POST /api/v1/integrations/wallet/events`, with `Authorization: Bearer YOUR_INGESTION_TOKEN` and `Content-Type: application/json`. This is **SpendTrace's contract**, not Apple's native payload schema.

```http
POST /api/v1/integrations/wallet/events HTTP/1.1
Host: your-spendtrace.example
Authorization: Bearer YOUR_INGESTION_TOKEN
Content-Type: application/json

{
  "card_key": "everyday-visa",
  "event_id": "d76c9008-66d8-4b5b-94b2-93c1cc6fb134",
  "observed_at": "2026-11-11T14:36:00-05:00",
  "transaction_at": "2026-11-11T14:35:00-05:00",
  "merchant": "Example merchant",
  "amount_decimal": "86.42",
  "currency": null,
  "test": true
}
```

- Required: UUID `event_id`, timezone-qualified ISO-8601 `observed_at`, nonempty merchant (max 200 characters), exact string `amount_decimal` (up to nine whole and six fractional digits), and `currency` (supported uppercase currency or null).
- Card routing: send `card_key` exactly matching a configured mapping (case-sensitive, max 100 characters). New multi-card connections require it for real purchases. Unknown keys return 422 without recording a purchase; they never fall back to a default account. Connectivity tests need no card key and do not verify the mapping. Legacy single-card connections still accept an omitted key for their original account.
- Optional: `transaction_at` (actual transaction timestamp with timezone, or null) and boolean `test` (defaults false). Omit `transaction_at` if only execution time is available; that time is labeled as observation-derived in the ledger. Do not fabricate it.
- Set `test:true` for connectivity tests; omit it or set false for real purchases. Tests are durably recorded in connection history but create no expense.
- The request is limited to 8 KiB, 60 submissions/minute per connection and 120/minute per originating IP. The bearer endpoint uses no browser session credentials and is in the stateless API middleware group. Browser settings and manual matching retain normal session authentication and CSRF protection.
- The user is derived solely from the credential’s connection; the account is resolved server-side from that connection’s configured card mapping. `user_id`, `account_id`, `connection_id`, `wallet_connection_id`, `transaction_id`, and every other unrecognized payload key are rejected, including null values.
- Decimal strings retain their printed scale. Currency is taken from the payload, otherwise the explicit connection default, otherwise remains unknown. Provenance records `payload`, `connection_default`, or `unknown`. Unsupported currency codes and amounts incompatible with a known currency's precision return 422 rather than rounding or assuming USD. Unknown-currency amounts remain visible and excluded from currency totals.
- A new event returns 201 with `{ "event_id": "...", "accepted": true, "replayed": false, "status": "pending" }` after the observation **and purchase** commit. Status may already be `matched`, `unmatched`, or `needs_review` if a worker has processed it; a test returns `test_received`. The response contains no account, user, receipt, or purchase identifiers.
- A replay of the same normalized payload returns 200 and `replayed:true`. Reusing that connection's event ID with different data returns 409. A different connection can use the same event ID independently. Preserve the UUID and original payload for retries; do not generate a new UUID for every network attempt.
- Missing, revoked, disabled, or deleted credentials return the same generic 401. Validation errors return 422, oversize bodies 413, incorrect content type 415, and rate limiting 429. An unsuccessful phone request is **not automatically queued or retried**. There is no offline delivery promise.
- Matching jobs may be delivered repeatedly without creating purchases. If matching dispatch/processing fails, the purchase is already safe; replay the identical event or use **Review matches → Retry matching**. Queue logs omit tokens and raw financial payloads. Avoid reverse-proxy/request-body/Authorization-header logging, and keep `APP_DEBUG=false`.

### Configure and verify on the physical iPhone

1. In SpendTrace, enable one connection, map each card key to one of your accounts, and create its shared token.
2. Open Shortcuts and create a personal automation with a **Transaction** trigger. Select the corresponding card under **When I tap**, as described in [Apple's transaction-trigger guide](https://support.apple.com/en-euro/guide/shortcuts/apd65c67538a/ios).
3. Choose automatic execution where supported by your installed iOS version. Labels and permission controls vary; see [Apple's personal-automation guide](https://support.apple.com/en-mide/guide/shortcuts/apdfbdbd7123/ios). Confirm locked-device/action permissions on the device.
4. Inspect the transaction input properties actually available. Apple's trigger documentation does not guarantee a complete merchant/amount/currency schema. Map only supported fields, format amounts as strings, and verify them against a real payment. Send `card_key` from a stable card property if available, or use a fixed key in a card-specific automation. All such automations share one SpendTrace connection/token. Apple’s guide documents selecting a card; do not assume one automation exposes identifiable events for every card without checking your device. If required fields are unavailable, do not invent values or claim automatic capture works on that device.
5. Generate one UUID per event and retain it with the payload if implementing retries. Include the current execution timestamp as `observed_at`, and only include `transaction_at` when a separate actual transaction timestamp is available.
6. Use **Get Contents of URL**, POST, JSON body, and the Authorization header shown above. Never put credentials in the URL. No downloadable shortcut or automatic installation is supplied.
7. Send `test:true` from the phone, wait for the server's `accepted:true`, and verify `test_received` under connection **Status / event history**. The browser test in settings proves only browser-to-server connectivity, not phone connectivity.
8. Verify a real in-store Apple Pay purchase: one event, correct account/amount/currency, and one Wallet-only purchase. Upload its receipt; verify a single counted purchase, images/items, and retained source evidence. Repeat in receipt-first order. Inspect ambiguous matches and try undoing an attachment.
9. Retry an identical event and verify no duplicate purchase. Disable the connection and verify rejection; rotate its token and verify the old token fails. Test a second user and account to confirm isolation.

Apple documents a card-tap trigger; it does not establish complete payment coverage. Historical Wallet transactions, physical-card purchases, online payments, and Apple Watch purchases are not guaranteed to be captured. Successful connectivity tests do not prove those events are captured. Physical-iPhone checks remain required and were not performed in this implementation session.

### Matching rules, source retention, and isolation

The same service handles Wallet processing and completed receipt extraction. It considers only the connection owner's receipts, never known conflicting financial accounts. Exact totals use scaled-integer arithmetic, never floats. Merchant normalization lowercases and collapses punctuation/whitespace; it does not remove location numbers or infer merchant aliases. Original values remain in source records.

Automatic attachment requires **one qualifying candidate in both directions**: identical normalized merchant, equal positive exact amount, both currencies known and equal, and time/account evidence. With the same known account, date-only receipts must have the same calendar date as the event in its supplied timezone. Timed receipts must be within **five minutes**; a known receipt timezone converts the event first, otherwise only the event's explicit offset is used. An unknown payment account additionally requires a supplied actual `transaction_at` and a timed receipt within five minutes. Execution time alone cannot bind an unknown-account receipt. Candidate review searches the adjacent calendar days for possible timezone-boundary cases, but never expands automatic tolerances. Changes to a Wallet purchase’s recorded date/time that contradict its original event require manual confirmation. Ambiguous candidates remain separate and count separately until resolved.

Unknown currencies, refunds/nonpositive totals, differing tips/authorizations/split-payment totals, and known currency/account conflicts never auto-merge. Equal unknown-currency printed values alone are insufficient. Manual attachment requires explicit confirmation and still enforces ownership, account compatibility, exact equal totals, and no known currency conflict. Correct source details before attaching incompatible totals; do not merge distinct payments to hide a difference. The review screen shows merchant, amount/currency, account, timestamp, source, and candidate receipt links.

For an attachment, the receipt purchase becomes the canonical ledger record; the original Wallet purchase is retained with a `merged_into_id` pointer and omitted from totals/lists. Its old URL redirects to the combined purchase. Receipt identity, original images, extraction history, and item rows stay intact. The Wallet observation attaches to the canonical purchase, while its immutable event payload and original purchase remain available. User-corrected fields take precedence; source disagreements become review reasons. Re-extraction never silently replaces attached Wallet amounts/currency with conflicting evidence. Matched purchases remain **bank-unreconciled**. Reviewing and matching do not change bank status.

**Undo attachment / count separately** restores the original Wallet purchase and observation, leaves receipt evidence and subsequent user corrections intact, and reverses unchanged automatic field fills. Both purchases then count separately. Confirming separate prevents future automatic matching for that event; a deliberate manual confirmation can still attach it later. Receipt and Wallet item amounts are never added again to the purchase total.

Ingestion, attachment, and ledger mutations serialize on the owner row before purchase/receipt locks. Slow AI network calls occur outside these locks; extraction generation and status are checked again before committing. A database unique constraint enforces `(wallet_connection_id, event_id)`; composite foreign keys bind each accepted event to its owning connection and resolved account under the same user. Card mappings are validated against owned accounts both when configured and when ingested. Credentials cannot be repurposed as browser credentials or redirect ownership. Queue jobs contain an internal event ID and derive the owner from the persisted connection and use the event’s immutable account snapshot, checking source ownership again. Manual and status endpoints enforce ownership server-side and return 404 for another user's records. Connection credentials are SHA-256 hashes of random 256-bit secrets; only the creation response contains the plaintext. Future webhook adapters must use separate credentials and the same connection → owner → allowed card/account mapping → immutable event account model, not reuse Wallet tokens.

Concurrent tests require PostgreSQL, `proc_open`, and a disposable database whose name ends in `_test`. They spawn independent PHP workers; on SQLite they are explicitly skipped. This version adds no third-party dependencies. Wallet migration rollback retains nullable receipt IDs so existing Wallet-only purchases are not destroyed; restoring the previous schema fully requires a considered data migration, not deleting evidence.

For an existing installation, the multi-card migration backfills event ownership/account snapshots without changing event payloads or idempotency hashes. Existing tokens continue to work. Use **Wallet → Configure → Add card** to expand an existing connection; no new token is required. One shared token can submit for all of its configured cards, so revocation applies to all of them.

## Shortcut installation, pairing, and alias onboarding

**Current release status: card-name mapping is implemented and genuine signed shortcut previews exist.** The Mac core has passed native payload, UUID/time, file-storage, pairing-fixture, and HTTP-fixture checks. Native Wallet extraction still needs six one-time property selections on the iPhone, followed by live HTTPS and physical-device validation. Install remains disabled. See [the native checkpoint and exact phone editor steps](resources/shortcuts/native-authoring-status.md).

Accessibility automation is available and Apple's editor was used to author and export the previews. The Mac's variable type menu does not include Transaction, Payment Method, or Currency Amount; these were confirmed on the user's iPhone. Their selectors are deliberately left for phone authoring rather than represented by guessed private identifiers.

The new settings panel separates **release available**, **installation unverified**, **pairing code redeemed / import credential issued**, **test received**, and **real event received**. A real unresolved event is not yet a purchase. Clicking an install link never marks the automation enabled or a device installed. Existing manual shortcuts, `card_key` mappings, and legacy connection tokens continue to work under the expandable advanced controls.

### Pairing protocol

1. An authenticated owner selects an enabled connection and a device name, then generates setup text. `POST /integrations/wallet/{connection}/pairings` uses session authentication and CSRF protection. It returns a random 128-bit code and expiry, shown once in page memory, plus the configured HTTPS pairing endpoint. Codes expire after ten minutes and are stored only as SHA-256 hashes.
2. The shortcut's **manual setup path** accepts the app-generated setup text once. It contains `pairing_url` and `code` as separate JSON fields; neither codes nor credentials are put in URLs. This setup text prevents users from manually constructing JSON or headers.
3. Redeem with `POST https://YOUR_HOST/api/v1/integrations/wallet/pair`, `Content-Type: application/json`, body `{"code":"THE_32_CHARACTER_PAIRING_CODE"}`. This endpoint requires HTTPS, rejects query parameters/extra ownership fields, limits bodies to 2 KiB, and allows ten attempts/minute per IP. Expired, used, invalid, disabled, or deleted-connection codes fail. Owner locking and a locked pairing record make redemption atomic; concurrent attempts issue exactly one credential.
4. A successful response contains a dedicated `wd_…` device token, approved HTTPS event endpoint, `schema_version: wallet-shortcut-v1`, shortcut version, `currency: null`, and `retry_policy: none`. Server responses have no-store headers. Only a SHA-256 hash of the random 256-bit device secret is stored. The server derives ownership from the pairing record, never from body identifiers, request host headers, or a shared master token.
5. Device tokens authenticate only Wallet ingestion and require HTTPS. Owners can revoke each device separately; disabling/deleting the connection rejects every credential underneath it. Existing accepted events remain available. Legacy token revocation affects the legacy token, not separately listed device credentials.

Configure `WALLET_PUBLIC_URL` to your reachable **HTTPS origin**, without a path, query, credentials, or fragment. Proxy TLS termination requires narrowly configured trusted proxies so Laravel can verify HTTPS. The origin setting does not create a tunnel or guarantee reachability. Mac hosting requires the Mac, server, database, and worker to remain running. There is no offline queue or automatic phone retry. A lost pairing response requires a new pairing code; inspect and revoke the unused device credential rather than allowing code reuse.

### Persistent configuration and explicit fallback

Apple documents the [Save File action](https://support.apple.com/en-gb/guide/shortcuts/apdaf74d75a5/9.0/ios/26) and [import questions](https://support.apple.com/en-ca/guide/shortcuts-mac/apdf330fd3a0/mac). The preview stores its returned device configuration as JSON in `Shortcuts/SpendTrace-config-v1.txt`, then reads it without a picker as its first action. Native file persistence and failed-pair preservation passed synthetic Mac checks. **Reliable target-iOS access while locked, after reboot, and with iCloud unavailable has not been verified.** File storage is not claimed to be Keychain storage; readable configuration and iCloud synchronization are material limitations.

The clearly labeled **Import-question fallback** issues a separately revocable device credential through the authenticated HTTPS `POST /integrations/wallet/{connection}/device-config` endpoint. The owner supplies only a device name; the app constructs the complete import configuration. A native shortcut release with an import question can accept that configuration directly, avoiding persistent-file pairing. It is explicitly a different provisioning method, not a claim that file-based pairing succeeded. The token is visible in the user's configured shortcut and may sync with Shortcuts. Never share/export the configured copy; shared templates must contain no token, personal endpoint, pairing code, or mappings. No native Keychain implementation is present. Because no native artifact is published yet, neither provisioning path has been exercised end-to-end on an iPhone.

### Wallet aliases and unresolved observations

The existing event contract also accepts this **explicit alternative** to `card_key`:

```json
{
  "event_id": "d76c9008-66d8-4b5b-94b2-93c1cc6fb134",
  "observed_at": "2026-11-11T14:36:00-05:00",
  "merchant": "Example merchant",
  "amount_decimal": "86.42",
  "currency": null,
  "wallet_alias": {
    "label": "Everyday Card",
    "last_four": "1234",
    "network": "Visa"
  }
}
```

This is SpendTrace's adapter contract, not a claim about Apple's native property names. `label` is required (1–100 characters); `last_four` is optional/null or exactly four digits as text; `network` is optional/null text up to 30 characters. Extra nested keys, numeric amounts, the string `"null"` for currency, and requests containing both `card_key` and `wallet_alias` fail validation. Existing decimal, currency-provenance, ownership, idempotency, timestamp, size, and rate-limit rules remain unchanged. Aliases are part of the idempotency payload; do not change them on a retry.

Alias matching is scoped to the authenticated connection and owner. It compares normalized label whitespace/case, actual suffix, and network together. **Name-only routing requires explicit owner confirmation that the name identifies one card on that connection.** Existing unconfirmed name-only mappings remain inactive. Exactly one owned-account mapping must qualify. Duplicate identifiers mapped to different accounts stay ambiguous. Network/label alone are not unique card identifiers. Do not fabricate a suffix or read a full card number to fill this field.

Unknown/ambiguous aliases return an accepted durable event with `status: needs_account`. They create **no transaction and no spending total**. In Wallet settings, the owner reviews the amount/source identifier, chooses an owned financial account, explicitly confirms it, and can remember the mapping when a suffix is available or explicitly confirm a unique card name. Assigning creates the purchase exactly once and invokes the existing matching service. Remembering affects future events only; earlier unresolved events require review, and existing assigned events never change accounts. Conflicting mappings can be removed in settings. Name-only inputs can be assigned individually without remembering a mapping, or explicitly mapped for future events. If two cards share a name, leave name-based routing disabled and use manual assignment or distinct card keys. This avoids unsafe per-card branching in the future shortcut, while acknowledging that insufficient device evidence may require manual review.

### Native artifact authoring and publishing steps still required

The remaining work is **not merely copying a link**: a native capture shortcut must first be authored and tested with real Wallet input. The authoring specification above defines separate setup, test, and background-capture paths and every server contract. End users should not build these actions themselves; these are publisher steps:

1. On the target iPhone, inspect the Transaction automation's real input and author **SpendTrace — Capture Purchase** in Apple's editor. Confirm available card label/suffix/network, merchant, amount, currency, and any actual transaction timestamp. Do not invent missing properties or promise one automation covers all cards; verify card selection and automatic execution on that device.
2. Implement a manual setup menu and a separate test path with `test:true`, amount string `"0"`, and JSON null currency. Wire setup to the code exchange, or use the explicit import-question fallback. Background capture must never open setup prompts when credentials or fields are missing; show an actionable failure and stop.
3. Generate a UUID once per event using an action verified on the target device, or a tested native random-hex composition with UUID version/variant bits. No universal native “Generate UUID” action is assumed. Format observation time as `yyyy-MM-dd'T'HH:mm:ssXXX`; omit actual transaction time unless independently provided.
4. Preserve the exact amount string. Use JSON dictionary serialization for escaping; never interpolate arbitrary merchant text into raw JSON. Do not use a currency formatter, binary-float arithmetic, or fixed-decimal rounding. If the available input is localized/ambiguous and cannot be converted exactly from known metadata, stop with a useful error. Preserve JSON null versus the text `"null"`.
5. POST with the private device token in the Authorization header. Validate the response has `accepted:true` before reporting success. `needs_account` means saved observation requiring account selection, not recorded spending. Handle 401/re-pairing, 409/conflicting replay, 422/invalid fields, 429/rate limit, and network failures without claiming success. Do not implement retries unless the full original payload and event ID are retained.
6. Validate setup/persistence after reboot and while locked, manual-test exclusion, two different cards, unknown/ambiguous aliases, no currency, exact fractional amounts, receipt matching in both orders, duplicate delivery, and revoked credentials. Remove all personal configuration before sharing.
7. In the Mac editor choose **File → Export → Anyone** for a genuine signed export. Alternatively, sign that genuine export using Apple's supported tool:

   ```sh
   shortcuts sign --mode anyone --input /path/to/genuine-export.shortcut --output /path/to/SpendTrace-Capture-Purchase.shortcut
   ```

   Apple receives a copy for signing/validation; export only a credential-free template. A signature does not verify Wallet behavior. No signing command was run on an invented plist in this implementation.
8. For the preferred iCloud distribution, open the validated shortcut in the editor, choose **Share → Copy iCloud Link → Share**, and copy the actual link. Verify clean installation from it on a physical iPhone. Apple's [sharing guide](https://support.apple.com/en-ge/guide/shortcuts-mac/apdf01f8c054/10.0/mac/27) documents the publishing steps.
9. Configure `WALLET_SHORTCUT_URL=https://www.icloud.com/shortcuts/ACTUAL_32_HEX_ID`, set a numeric `WALLET_SHORTCUT_VERSION` such as `1.0.0`, and set `WALLET_SHORTCUT_VERIFIED=true` **only after validation**. Run `php artisan config:clear`. The verified flag is an operator release attestation, not automated Apple signature verification. Without a correctly shaped link, a release version, and that explicit attestation, the Install button remains unavailable.
10. Users still manually install, pair/grant permissions, select payment cards and automatic execution in a Wallet automation, select the installed shortcut, and verify a first actual purchase. Installation alone cannot enable an automation. Nothing here claims physical installation or live capture has been tested.
