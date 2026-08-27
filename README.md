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

Every Pickupsheet screen is protected by the dedicated session login portal at `/dhl/pickupsheet/login`; HTTP Basic credentials no longer grant application access. Login IDs may be either usernames or email addresses. Every managed account requires a first name, last name, role, and explicit Active/Inactive status. The authenticated account's full name is displayed as the read-only Check By value and is enforced again by the server on create and edit, so a submitted value cannot impersonate another checker. Every new sheet starts `open`. A `viewer` can create, list, and paginate records but cannot edit them. An `operator` can create, view, edit, print, export, and change an open sheet to `paid`. An `admin` receives the activity dashboard, KPI graph, complete record visibility, edit/print/export access, the open-to-paid action, audited soft deletion, and user management. Edits preserve immutable references and consent metadata, recalculate totals, and write pseudonymous before-and-after snapshots. Paid transitions and deletions are separately audited; deleted records disappear from active views without destroying their database history. Administrators are bootstrap accounts defined in the server-managed `.env`; from the RBAC area an administrator can confirm the current password and store a secure replacement hash in MySQL, which immediately invalidates the previous password and session. The same area creates database-backed viewer/operator accounts and resets their login ID, names, role, password, or account status. Passwords are stored only as one-way hashes, sessions expire after one hour idle or eight hours total, and name/role/password/status changes invalidate managed-user sessions. Excel downloads contain only shipment-detail columns, shipment rows, and a bold shipment-total row; pickup-sheet header metadata and sequential row numbering are excluded. The records view uses database-backed pagination at 10 sheets per page, with progressively enhanced AJAX loading, a visible spinner, browser-history support, and standard links as a no-JavaScript fallback. Successful, forbidden, and denied records access is security-logged using hashed actor, client, and record identifiers. Legacy `/pickupsheet` URLs redirect permanently to the new `/dhl/pickupsheet` location.

Google Analytics measurement ID `G-WVFXFB5H3M` is included exactly once immediately after `<head>` through both shared page layouts. Consent Mode starts with analytics and advertising storage denied; analytics storage is granted only after a visitor accepts, advertising consent remains denied, and visitors can reopen their choice from the footer. Before acceptance, Google may receive cookieless consent-state pings but cannot set Analytics cookies through this configuration. Pickup-sheet operational pages suppress Analytics page views and sanitize page location so record-reference query values are not sent to Google. The Content Security Policy permits only the Google Tag Manager script origin and Google Analytics collection origins required by the tag. Because Google's tag is dynamically updated and cannot use a stable Subresource Integrity hash, its narrowly scoped CSP exception is an explicit third-party resource decision.

## Local development

1. Copy `.env.example` to `.env` and adjust the values.
2. Run `php bin/generate-records-credentials.php records-admin admin "First" "Last"`, save the generated password in a password manager, and copy the generated `PICKUPSHEET_RBAC_USERS` line into `.env`.
3. Start MySQL with `docker compose up -d`.
4. Run `php -S 127.0.0.1:8080 router.php`.
5. Open `http://127.0.0.1:8080`.

Run the dependency-free checks with:

```shell
php tests/run.php
```

## cPanel deployment

The `.cpanel.yml` recipe targets `/home/ttecwymc/public_html`, making this application the root website. It preserves the server-managed `.env`. Back up the existing WordPress files and database before the first root deployment.

Before deploying the hardened records access, run `php bin/generate-records-credentials.php records-admin admin "First" "Last"` on a trusted local computer. Put the quoted five-part `PICKUPSHEET_RBAC_USERS` setting inside `/home/ttecwymc/public_html/.env` and keep the generated password only in a password manager. Multiple environment accounts can still be joined with semicolons. Existing three-part entries remain compatible and derive a fallback name from the login ID, but the five-part format should be used so administrator actions carry the correct full name. Only an environment-defined admin can manage lower-tier accounts through the site. The older `PICKUPSHEET_RECORDS_USER` and `PICKUPSHEET_RECORDS_PASSWORD_HASH` settings continue to work as one admin account during migration; optional `PICKUPSHEET_RECORDS_FIRST_NAME` and `PICKUPSHEET_RECORDS_LAST_NAME` settings provide that legacy administrator's name. Also ensure `APP_ENV=production` and normally keep `RUN_MIGRATIONS=false`. Unauthenticated Pickupsheet requests redirect to the login portal, and login fails closed until at least one valid account is configured.

This release uses migrations `005_create_pickup_records_users.sql` through `009_add_pickup_records_user_names.sql`. Authenticated workflows initialize missing account, audit, lifecycle, administrator-credential, and user-name storage when the runtime MySQL user has the required schema permissions, supporting cPanel accounts without CLI access. Login and unauthenticated requests cannot create schemas. If the runtime MySQL user does not have `CREATE` and `ALTER` permission, use cPanel phpMyAdmin to execute migrations `005` through `009`, then keep `RUN_MIGRATIONS=false`.

The deployment normalizes copied directory permissions to `755` and file permissions to `644`. This prevents LiteSpeed from showing a directory listing while returning `404` for files that exist when the cPanel repository checkout has restrictive permissions. After pulling `main` in cPanel Git Version Control, select **Deploy HEAD Commit**. The recipe removes stale entry points and development-only artifacts, installs a readable `.htaccess`, and verifies the PHP entry point and stylesheet before succeeding.
