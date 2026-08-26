# T&Tech Consulting Group

The corporate website for `ttechcg.com`, built as a dependency-light PHP 8.2 modular monolith with an MVC HTTP boundary and MySQL persistence.

## Modules

```text
src/
├── Modules/
│   ├── Site/         # Corporate home, services, about, and health routes
│   ├── Contact/      # Inquiry domain, application service, adapters, and controller
│   └── Pickupsheet/  # Cash-shipment entry and protected records
└── Shared/           # HTTP kernel, views, security, environment, and database
```

The site uses MySQL through `pdo_mysql`. Valid database settings enable persistent contact inquiries and pickup sheets. When MySQL is unavailable, the public site remains reviewable with session-backed development adapters, while production data-entry forms fail closed. Local development runs idempotent migrations on application boot. Production does not run schema-changing migrations unless `RUN_MIGRATIONS=true` is explicitly set, allowing the runtime database user to be restricted to the data operations the application needs.

Production contact submissions require both a working MySQL connection and a valid `CONTACT_EMAIL`. Set `CONTACT_EMAIL=info@ttechcg.com` in the server-managed `.env`. Successful inquiries are stored first and then forwarded to that address through the hosting account's PHP mail transport. If either dependency is missing, the form is disabled and `/health` returns `503` instead of presenting a false success. Set `CONTACT_FROM_EMAIL` to a same-domain mailbox authorised by the hosting account.

The contact workflow uses CSRF validation, a honeypot, persistent client rate limiting, a first-party arithmetic CAPTCHA, and an explicit privacy opt-in. The CAPTCHA requires no external keys or tracking service and expires after 15 minutes. Accepted inquiries retain the consent timestamp and privacy-notice version for auditability. Security-control failures are written as structured events to the hosting account's PHP error log without form contents or other submitted personal data.

The unlisted `/dhl/pickupsheet/` route captures the supplied paper pickup-sheet structure: agent, date, and repeatable cash-shipment rows containing consignor, AWB, destination, amount, pieces, weight, and checker. The server records one submission time for every shipment on the sheet, so operators cannot alter the collection time. Every new sheet receives a 128-bit random `PS-YYYYMMDD-XXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXX` reference; existing 64-bit and legacy references remain readable. Shipment count and total XAF are calculated in the browser for immediate feedback and recalculated on the server before the aggregate and its line items are committed together. CSRF, one-use CAPTCHA, honeypot, persistent client rate limiting, field validation, and a recorded privacy opt-in protect submissions.

The pickup-sheet entry form remains public, and “Checked by” is a required field entered with each shipment. Stored records are fail-closed: `/dhl/pickupsheet/submissions`, individual print views, and native `.xlsx` exports require HTTPS Basic credentials whose password hash exists only in the server-managed `.env`. Excel downloads contain only shipment-detail columns, shipment rows, and a bold shipment-total row; pickup-sheet header metadata and sequential row numbering are excluded. The records view shows the 100 most recent sheets. Successful and denied records access is security-logged using a hashed client identifier and hashed record identifier. Legacy `/pickupsheet` URLs redirect permanently to the new `/dhl/pickupsheet` location.

Google Analytics measurement ID `G-WVFXFB5H3M` is included exactly once immediately after `<head>` through both shared page layouts. Consent Mode starts with analytics and advertising storage denied; analytics storage is granted only after a visitor accepts, advertising consent remains denied, and visitors can reopen their choice from the footer. Before acceptance, Google may receive cookieless consent-state pings but cannot set Analytics cookies through this configuration. Pickup-sheet operational pages suppress Analytics page views and sanitize page location so record-reference query values are not sent to Google. The Content Security Policy permits only the Google Tag Manager script origin and Google Analytics collection origins required by the tag. Because Google's tag is dynamically updated and cannot use a stable Subresource Integrity hash, its narrowly scoped CSP exception is an explicit third-party resource decision.

## Local development

1. Copy `.env.example` to `.env` and adjust the values.
2. Run `php bin/generate-records-credentials.php`, save the generated password in a password manager, and copy the two `PICKUPSHEET_RECORDS_*` lines into `.env`.
3. Start MySQL with `docker compose up -d`.
4. Run `php -S 127.0.0.1:8080 router.php`.
5. Open `http://127.0.0.1:8080`.

Run the dependency-free checks with:

```shell
php tests/run.php
```

## cPanel deployment

The `.cpanel.yml` recipe targets `/home/ttecwymc/public_html`, making this application the root website. It preserves the server-managed `.env`. Back up the existing WordPress files and database before the first root deployment.

Before deploying the hardened records access, run `php bin/generate-records-credentials.php` on a trusted local computer. In cPanel File Manager, add the generated `PICKUPSHEET_RECORDS_USER` and quoted `PICKUPSHEET_RECORDS_PASSWORD_HASH` values to `/home/ttecwymc/public_html/.env`. Also ensure `APP_ENV=production` and `RUN_MIGRATIONS=false`. The records routes return `401` until a valid password hash is configured; the public pickup entry form continues to operate.

For future schema changes, temporarily set `RUN_MIGRATIONS=true`, make one controlled application request after deployment, confirm success, and immediately return it to `false`. The runtime MySQL user should then have schema-changing privileges removed.

The deployment normalizes copied directory permissions to `755` and file permissions to `644`. This prevents LiteSpeed from showing a directory listing while returning `404` for files that exist when the cPanel repository checkout has restrictive permissions. After pulling `main` in cPanel Git Version Control, select **Deploy HEAD Commit**. The recipe removes stale entry points and development-only artifacts, installs a readable `.htaccess`, and verifies the PHP entry point and stylesheet before succeeding.
