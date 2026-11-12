# Native shortcut checkpoint — November 12, 2026

## Architecture change: native local capture

Wallet is now mobile-only. The native Xcode project is `native/SpendTrace/SpendTrace.xcodeproj`. It exposes a local **Record purchase** App Intent and stores data in the app's SwiftData database without CloudKit. Browser Wallet controls/prompts were removed; the former server routes default to HTTP 410 (`WALLET_SERVER_ENABLED=false`). Existing server data is retained. The server-based shortcut previews and pairing instructions below are historical, not the current installation flow. Do not publish them as the native local-capture release.

The first native build includes ledger, manual entry, accounts, confirmed card links, and the App Intent. Receipt capture/OCR, receipt matching, export, optional server sync, and a finished native-action automation template remain future work. Physical Wallet execution and locked-device behavior are not yet verified.

## Mobile installation and same-iPhone connection — 2026-11-12

The web app now has a standalone manifest, PNG home-screen icons, iOS installation guidance, a browser install prompt when supported, mobile bottom navigation, and a public offline page. The service worker caches only that offline page and app icons. It does not cache private pages, receipt images, API responses, or queue offline writes.

The regular Wallet setup no longer asks users to copy JSON. After a validated shortcut release is installed, **Connect Shortcut** calls the authenticated `POST /integrations/wallet/{connection}/connect` endpoint. That endpoint requires HTTPS and ownership, rejects ownership overrides, and returns a `shortcuts://run-shortcut` link with text input containing `action: spendtrace-connect-v1`, the pairing URL, and an expiring single-use code. It never includes the durable ingestion token. The shortcut exchanges the code in a POST body and saves configuration through the existing native Save File path. The original ownership/isolation and ingestion contracts are preserved.

### Genuine mobile preview

- Artifact: `resources/shortcuts/SpendTrace-Capture-Purchase-Mobile-Preview.shortcut`
- Native editor name: **SpendTrace Mobile Ready Preview** (rename to **SpendTrace — Capture Purchase** only as part of final publisher preparation; the app launches that final name).
- SHA-256: `126c57cf784205bf656c33738c5d43388a1957375769a9239abe09a3d85853ed`
- Exported through Apple's editor and signed with `shortcuts sign --mode anyone` (exit 0).
- Source action representation: `capture-purchase.mobile-authoring-actions.plist`; not an installable file.
- No personal credentials are embedded. The native runtime reads/writes the user's private configuration file.
- Mac tests confirmed setup recognition despite Apple reordering JSON keys, HTTP-address rejection, and malformed-code rejection before submission. They do not establish a successful live connection.

### Remaining publisher work

The mobile preview still deliberately stops Wallet capture at **Publisher setup required**. Apply the same six iPhone-only variable selections in the instructions below, then remove only that guard. Its manual menu now says **Connect to SpendTrace** (instructions to use the app) and **Test connection**; no manual JSON setup is needed in this preview. The earlier preview artifacts and their manual-pairing instructions remain historical development references.

Install the mobile preview on the iPhone by AirDrop or Files, edit the six properties, rename it to the final name, and export the unconfigured template back for signing. A clean physical-iPhone install, actual same-phone URL launch, successful HTTPS exchange and configuration persistence, two real-card purchases, locked-device/reboot behavior, and receipt matching still need validation. The current VS Code tunnel was previously rejected as HTTP by Laravel; this change does not bypass that protection or fix the tunnel.

Only after those checks publish the genuine iCloud URL and set `WALLET_SHORTCUT_VERIFIED=true`, a numeric version, and `WALLET_SHORTCUT_HANDOFF_VERIFIED=true`. Install and Connect remain gated. Use a stable HTTPS deployment origin for the final home-screen app and shortcut configuration.

Validation: production frontend build and TypeScript passed; 86 Wallet/mobile tests passed, two PostgreSQL-only tests skipped; four service-worker tests passed. The running local server returns the correct manifest, JavaScript, and PNG content types. Physical iOS/Android installation has not been tested here.


**Mapping is implemented. The native shortcut core is tested on Mac. Full Wallet capture is not yet release-validated.** Six one-time iPhone-only property selections, deployment, and physical-device checks remain. Install remains disabled; no iCloud release URL is claimed.

## Genuine signed artifacts

Both files were exported from Apple's Shortcuts editor and signed successfully with `shortcuts sign --mode anyone` (exit code 0). They contain no personal device token, pairing code, server origin, account IDs, or mappings. Configuration lives separately in the user's Shortcuts folder.

- `SpendTrace-Capture-Purchase-Preview.shortcut` — 39,445 bytes; SHA-256 `ba5a85bc4702ac26ede17a027f1a2e93f61bafbd4b2c9faf5c86deeb80b19dbc`.
- `SpendTrace-Capture-Purchase-iPhone-Authoring.shortcut` — 40,853 bytes; SHA-256 `ee782ee0ebc49a25e65417610893709a5a4e8e5c38afffab4fe9617a0a8393aa`.

- **iPhone Authoring** is the publisher's next step. It contains six named extraction actions and a Stop action that prevents capture until the phone-specific selectors are configured. Pairing and Test connection are usable independently.
- **Preview** is the tested core. Its capture input is a dictionary containing `merchant`, textual `amount_decimal`, optional `currency`, and `card_name` (or a nested `wallet_alias`). It does not extract a native Wallet Transaction by itself.
- The adjacent `*.native-actions.plist` and `*.iphone-authoring-actions.plist` files preserve action-source representations, not installable files. Do not rename them to `.shortcut`.

## One-time publisher edits on the iPhone

The Mac editor's Type menu lacks Transaction, Payment Method, and Currency Amount. Accessibility permission is working; this is a content-type availability limitation, not a permission request. The property names below were verified from the user's iPhone screenshots.

1. Transfer `SpendTrace-Capture-Purchase-iPhone-Authoring.shortcut` by AirDrop or Files. Open it and choose **Add Shortcut**. Its name is **SpendTrace iPhone Authoring**.
2. Open its editor. In the capture **Otherwise** branch, locate the Stop action beginning **Publisher setup required**. Immediately below are six Set Variable actions.
3. For each action, tap its **input token** (the value after “to”), not its variable-name field. Set the following property, preserving the source variable:

| Named variable | Source token | Select this property |
| --- | --- | --- |
| WalletMerchant | Shortcut Input, type Transaction | Merchant |
| WalletCard | Shortcut Input, type Transaction | Card or Pass |
| WalletCardName | WalletCard, type Payment Method | Name |
| WalletAmount | Shortcut Input, type Transaction | Amount |
| WalletCurrency | WalletAmount, type Currency Amount | Currency Code |
| WalletAmountValue | WalletAmount, type Currency Amount | Currency Amount — the property below Currency Code, not the whole-object entry |

The following Get Text action and dictionary construction are already connected. Do not add rounding, arithmetic, or currency formatting. Ambiguous/localized strings are rejected instead of silently changing the amount.

If a required type is not offered in the standalone shortcut editor, configure the corresponding Set Variable action in the Transaction automation where the type is available, then use Apple's action Copy/Paste to put that typed action in the corresponding position here, preserving the variable name. Do not guess hidden selectors.

4. After all six selections are correct, delete **only** the Stop action beginning **Publisher setup required**. Preserve the other validation Stop actions.
5. Rename the shortcut **SpendTrace — Capture Purchase**. Export the unconfigured template using Apple's sharing/export UI and return that genuine export to the Mac for final signing and review. This is a one-time publisher step; users of the finished template should not construct the extraction actions themselves.

## Deployment and device validation

The attempted Cloudflare tunnel could not connect on either UDP or TCP port 7844; tunnel attempts were stopped. Ngrok was not started. `WALLET_PUBLIC_URL` is blank locally so the app does not advertise a dead URL. The user plans to deploy under spendwoven.com. Do not infer whether the canonical origin will be the apex, www, or the supplied wwww hostname.

On the deployed service:

1. Set `APP_URL` and `WALLET_PUBLIC_URL` to the actual reachable HTTPS origin. Configure `TRUSTED_PROXIES` only for the hosting provider's proxy addresses when TLS terminates upstream, then clear cached configuration. The origin setting does not deploy the service or configure DNS/TLS.
2. Sign in, create/enable the owned Wallet connection, and generate its pairing setup text. Run the installed shortcut manually → Pair / replace configuration and paste the complete setup text.
3. Run manually → Test connection. Verify `test_received` in SpendTrace; no expense should be created.
4. In the iPhone Transaction automation, select the cards, choose automatic execution where available, and add **Run Shortcut → SpendTrace — Capture Purchase**, passing the original **Shortcut Input** Transaction. Grant needed permissions. The screenshot confirmed one trigger can select the user's 12 Wallet passes; this is not proof every payment category triggers reliably.
5. Test actual purchases on two cards, exact amount/currency, unknown card names, duplicate names, locked-device and reboot behavior, receipt matching, revoked credentials, and a clean installation. Do not mark the release verified until these pass.
6. After verification, publish with **Share → Copy iCloud Link** and configure the real link, numeric release version, and verified release flag. Publication is not the only remaining step today.

## Card-name mapping behavior

Owners can enter a Wallet card name upfront or remember a name while assigning a discovered observation. Name-only routing requires explicit confirmation that the name identifies one card on that connection. Existing unconfirmed mappings remain inactive. Multiple matching accounts remain unresolved. Only the authenticated owner's accounts can be selected; routing is scoped to the credential's connection and owner.

Apple exposes only Payment Method → Name in the inspected input. If two real cards share a name, the input cannot distinguish them: do not confirm that name as unique. Use manual assignment, or the existing advanced card-key integration with separate card-specific automations. Mapping changes affect future events only; queued events and existing purchases retain their saved accounts. The shortcut never supplies user/account/connection ownership IDs.

## What passed

- 81 Wallet feature tests, 648 assertions; two PostgreSQL-only concurrency tests skipped in the SQLite run. Coverage includes confirmed/unconfirmed names, conflicts, cross-user and cross-connection mapping attempts, manual assignment, queued account preservation, test exclusion, and rejection of spoofed HTTPS headers from untrusted clients.
- TypeScript checking and production frontend build. The local name-confirmation migration was applied.
- Native UUIDv4 generation, exact amount strings, JSON null/boolean preservation, quotation escaping, and ISO timestamps with time and offset.
- Native nested alias serialization and rejection of empty merchant, ambiguous decimal text, and invalid suffixes.
- Native configuration save/load with and without shortcut input. The configuration is JSON text in `Shortcuts/SpendTrace-config-v1.txt`; file lookup is the first action so preceding output cannot become its folder argument.
- Native pairing branch against a loopback fixture: code-only JSON request, response validation, saving the response, and preservation of the previous configuration after a failed exchange.
- Native capture HTTP action against a loopback fixture: bearer header, JSON content type, exact fields/types, UUID/timestamp, nested alias, and acceptance/status handling.
- Native manual Test connection menu path against a loopback fixture: amount string `0`, null currency, `test: true`, and `test_received` response.
- iPhone-authoring capture guard: capture stops until the publisher finishes the selectors and removes that specific guard.

The loopback fixtures used dummy credentials and synthetic data; they were not live HTTPS or physical-Wallet tests. Apple's own HTTP/network failures stop execution and use the native error display. The shortcut has no automatic retries or offline queue. File storage is not Keychain storage and can sync through iCloud; locked-device access is still a physical-device check.
