# SpendTrace

A working personal receipt ledger built with Laravel 13, Inertia 3, Vue 3 + TypeScript, and PostgreSQL. Capture multiple photos of one receipt, save originals privately, extract asynchronously, and correct only what needs attention. This milestone does not connect to banks or Apple Wallet.

## Requirements

- PHP **8.4+** for the locked dependencies, Composer 2, and extensions `pdo_pgsql`, `fileinfo`, `mbstring`, `openssl`, `xml`, `curl`, `ctype`, `tokenizer`, `session`, and `dom`. Tests also use `gd` and `pdo_sqlite`. Queue workers need `pcntl` for enforced timeouts on Unix.
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

Source references: [Laravel 13 prerequisites](https://laravel.com/framework/docs/releases), [Inertia 3 setup](https://inertiajs.com/docs/v3/installation/client-side-setup), [OpenAI image inputs](https://developers.openai.com/api/docs/guides/images-vision), and [structured outputs](https://developers.openai.com/api/docs/guides/structured-outputs).

## Data and reliability

- An authenticated upload accepts 1–6 validated JPEG/PNG/WebP images, at most 10 MB and 50 megapixels each. HEIC/HEIF gets actionable conversion instructions. All files are validated before any are saved.
- Originals live under `storage/app/private/receipts/`. Do **not** expose this directory or link it into `public/`. Every image request verifies ownership and returns no-store headers. Eloquent storage paths and hashes are hidden from page props.
- Upload creates an unreconciled transaction with nullable fields and persists originals before dispatching a durable database job. Closing the browser after upload does not stop the queue.
- Exact image hashes are unique **per user**; identical uploads reopen the existing receipt. Partial overlap is rejected with guidance, avoiding accidental extra transactions. Resized/recompressed images are not exact matches.
- Extraction jobs serialize on a PostgreSQL receipt row lock, check generation, and use the unique receipt-to-transaction relationship. Re-delivery cannot create another transaction. A retry increments the generation; obsolete jobs cannot replace newer results. One worker attempt is made; failures can be retried explicitly from receipt details.
- A worker killed before it marks failure can leave a receipt queued. Start the worker again or use Retry; the generation guard makes stale queued deliveries harmless. `php artisan queue:failed` inspects framework-level failures without displaying financial content.
- Extraction runs preserve each valid structured result separately. Edits track corrected fields; changing a money value protects its currency too. Marking a transaction reviewed protects all reviewed fields/items. Reprocessing updates only uncorrected fields and returns the receipt to Needs review. A revision check prevents overwriting changes that arrived while an edit form was open.
- Account suggestions use physical or device-specific last-four identifiers. Ambiguous suffixes require a choice; one match remains a suggestion to confirm. Supported institution labels include Bank of America, Capital One, Chime, and Synchrony; use your own product nicknames.
- Likely duplicates compare normalized merchant, purchase date, amount, currency, and account when known. Nothing merges automatically. Open the other receipt and optionally exclude one record from totals; its evidence stays available.
- Summaries sum transaction totals, **never items**, per currency, from the start of the current month through today in America/New_York. Future purchase dates are not included. Known but unreviewed amounts are included; missing dates, currencies, or totals are excluded. The unresolved count spans the ledger, so incomplete records cannot disappear behind an upload-month assumption. Excluded records are omitted. Refunds can be recorded as negative amounts.
- Arithmetic checks use `subtotal + tax + tip − additional discount` only when the provider confirms that interpretation of the layout. Discounts already reflected in subtotal must not be subtracted twice. Unknown components and unsupported layouts are review reasons. Items are not assumed complete or automatically summed to replace the receipt total.
- Receipt observations provide source type, source ID, payload, and observed-at extension points for later Wallet events, bank alerts, and imported transactions. Those integrations are **not implemented**. A receipt never marks a purchase bank-reconciled.

Application code does not log receipt images, text, suffixes, or provider bodies. Avoid adding request logging or debug tooling to financial routes. Back up both PostgreSQL and private storage together, protect backups, and restrict file permissions. Do not upload receipts containing unmasked full card numbers, CVVs, or banking credentials; originals are preserved as supplied, not redacted. The application has no fields for those values.

## Verification

```sh
php artisan test --compact
npm run build
vendor/bin/pint --format agent
```

Implementation verification: **24 tests, 162 assertions passed on both SQLite and local PostgreSQL**; TypeScript/Vite production build and HTTP page/asset smoke checks passed. Live AI and physical iPhone checks were not run.

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

No live bank/Wallet, SMS/email, voice, subscriptions, or household integrations. No automated HEIC conversion, OCR accuracy guarantee, exchange rates, or visual duplicate-image comparison. No password-reset/email-verification flow or account deletion UI in this milestone. For a public deployment, add those lifecycle flows, email delivery, an HTTPS web server/process supervisor, storage backups, and registration controls appropriate to your deployment.

The real provider is implemented and tested with mocked HTTP, but live provider accuracy requires an API key and your own receipts. This environment has no browser-control connection, so Safari/camera and visual rendering checks remain manual. The repository includes the exact iPhone checklist above rather than claiming those device checks passed.
