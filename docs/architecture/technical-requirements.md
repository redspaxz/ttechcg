# Technical requirements

What the application needs to run, and the technical rules it must keep. Configuration variables are listed in the [README](../../README.md#environment-configuration). Controls mapped to ISO 27001 are in [iso-27001-application-controls.md](../security/iso-27001-application-controls.md).

Related: [Application flows](flows.md) · [Data requirements](data-requirements.md)

## Platform

| Requirement | Value |
|---|---|
| Language | PHP 8.2 or later, no framework, no Composer runtime dependencies |
| Required PHP extensions | `pdo_mysql`, `openssl`, `curl` |
| Recommended PHP extensions | `mbstring` (uppercasing accented names, with an ASCII-only fallback), `intl` (accent folding in CRM duplicate review) |
| Database | MySQL 8 or compatible, InnoDB, `utf8mb4` / `utf8mb4_unicode_ci`. Migrations 020 onward use `REGEXP_REPLACE` |
| Web server | Apache or LiteSpeed with `.htaccess` front-controller routing. `router.php` for the PHP built-in server |
| Deployment | cPanel Git deployment (`.cpanel.yml`) |
| Transport | HTTPS in production. HSTS is sent and session cookies are `Secure` |
| Writable paths | `storage/sessions`, `storage/security` |
| Time zone | `APP_TIMEZONE`, default `Africa/Douala`. Timestamps are stored in UTC and shown in the application time zone |
| Browsers | Current Chrome, Edge, Firefox, Safari and their mobile versions. Name-field filtering uses Unicode property regular expressions (Chrome 64+, Firefox 78+, Safari 11.1+). Pages work without JavaScript |
| Layout | Phones from 360px wide. Wide tables scroll inside their own card |
| Tooling | Node.js only for script checks and JavaScript tests, never at runtime |

## Architecture

- **Modular monolith.** Each module under `src/Modules` (Site, Contact, Pickupsheet, CRM, Backup) has `Domain`, `Application`, `Infrastructure`, and `UI` layers. Shared HTTP, security, storage, spreadsheet, text, and view code lives in `src/Shared`.
- **Ports and adapters.** Every repository has a MySQL, a Demo (session-backed, for local use and tests), and an Unavailable adapter. When storage is missing, writes fail closed and the public site stays readable.
- **Server-authoritative.** The server recalculates totals and stamps the agent, checker, collection time, and (except for administrators) collection date. Browser checks only help the user.
- **Progressive enhancement.** AJAX pagination, modals, autocomplete, and live field filtering sit on top of working links and forms.
- **Cache busting.** After changing `styles.css`, `app.js`, `print.css`, or `print.js`, bump its `?v=` value in `views/layouts/` and the matching assertion in `tests/run.php`.

## Roles and permissions

Permissions are checked on the server for every protected action (`src/Shared/Security/RecordsPrincipal.php`).

| Permission | Allows | Viewer | Operator | Admin |
|---|---|:-:|:-:|:-:|
| `create` | Submit a new pickup sheet | ✓ | ✓ | ✓ |
| `list`, `paginate` | View and page through submitted sheets | ✓ | ✓ | ✓ |
| `print`, `export` | Print/PDF a sheet, export a sheet or the CRM directory to Excel | | ✓ | ✓ |
| `mark_paid` | Confirm payment with a receipt | | ✓ | ✓ |
| `crm_view` | Open the CRM directory and profiles, follow consignor links | | ✓ | ✓ |
| `crm_update` | Edit customer details, log activity, close follow-ups | | ✓ | ✓ |
| `crm` | Add, rename, merge, undo, ignore, and delete customers, adjust points | | | ✓ |
| `set_collection_date` | Choose a date other than today on a new sheet | | | ✓ |
| `edit`, `edit_receipt`, `delete` | Correct sheets, change receipt numbers, delete sheets | | | ✓ |
| `dashboard`, `report` | Dashboard tabs and printable performance reports | | | ✓ |
| `manage` | Users, MFA resets, sign-in methods, collection agent, admin password | | | ✓ |
| `backup` | Download and restore encrypted backups | | | ✓ |

## Security requirements

| Area | Requirement |
|---|---|
| Passwords | Argon2id (19 MiB memory, time cost 2) where available, otherwise bcrypt cost 12 |
| MFA | RFC 6238 TOTP for local accounts when `PICKUPSHEET_LOCAL_MFA_ENABLED=true`. Secrets encrypted with `PICKUPSHEET_MFA_ENCRYPTION_KEY`, recovery codes hashed and single-use, time steps not reusable. Production local login fails closed if MFA is required but not configured |
| SSO | JumpCloud OIDC with state, nonce, and PKCE (S256). RS256 ID-token signature checked against the published keys. Optional Cloudflare Access JWT, with roles from groups |
| Sessions | Cookie-only, `HttpOnly`, `SameSite=Lax`, `Secure` over HTTPS. 8 h absolute, 1 h idle, session ID renewed every 15 min and at sign-in |
| Request integrity | CSRF token on every state-changing form. Cross-site unsafe requests are refused using `Sec-Fetch-Site` and `Origin` |
| Headers | Content-Security-Policy, HSTS (1 year), `X-Frame-Options: DENY`, `nosniff`, `Referrer-Policy: strict-origin-when-cross-origin`, Permissions-Policy denying camera, microphone, and location. Protected pages are `no-store` and `noindex` |
| Input | All business data validated on the server (see [data requirements](data-requirements.md#field-rules)). Output escaped with `htmlspecialchars`. SQL uses prepared statements, and `LIKE` wildcards are escaped |
| Redirects | Return links (`from=`, print back links) accept only same-site Pickupsheet paths |
| Human checks | Captcha on the contact form. A single-use server question before marking paid or changing a receipt |
| Logging | Security events are pseudonymous: actors, targets, and resources are 24-character hashes, and clients are 64-character hashes. No passwords, tokens, or secrets are logged |
| Backups | AES-256-GCM, key from the operator's passphrase via PBKDF2-SHA256 with 210,000 iterations. Restore validates format and version, and is transactional. 12 MiB maximum |
| Secrets | Only in the server environment, never in source or browser scripts. `bin/security-check.php` scans the repository in CI |

### Rate limits

Per client unless stated otherwise. When a limit is hit the server returns `429` with `Retry-After`.

| Action | Limit |
|---|---|
| Local login | 10 per 15 min per client, and 15 per 15 min per account |
| MFA code | 10 per 15 min per client and account |
| JumpCloud callback, Cloudflare Access sign-in | 20 per 15 min |
| Contact form | 10 per hour |
| Submit pickup sheet | 30 per hour |
| Edit sheet | 60 per hour |
| Mark paid, receipt change, delete | 30 per hour each |
| Payment security question | 60 per hour |
| User and RBAC changes | 30 per hour |
| Consignor and CRM autocomplete | 180 per 5 min |
| CRM profile writes | 60 per hour. Merges 20 per hour, point changes 40 per hour, exports 10 per hour |

## Availability and operations

- `GET /health` returns `{"status":"ok"}` with `no-store`, without component details.
- Migrations run only when `RUN_MIGRATIONS` allows it. Back up before migrations that fold CRM profiles (018, 020).
- Security events and session activity are pruned at most once a day after `SECURITY_EVENT_RETENTION_DAYS` and `SESSION_ACTIVITY_RETENTION_DAYS` (default 365, bounded 30–3650).
- CRM synchronization is incremental (`pickup_crm_sync_state`). Lists page at 10, 25, or 50 rows. The CRM export is capped at 5,000 customers.

## Quality gates

CI (`.github/workflows/verify.yml`, PHP 8.2) must pass before release:

- `.cpanel.yml` parses and lists its deployment tasks as strings
- `php -l` on all PHP files
- `php bin/security-check.php`
- `php tests/run.php` (application, security, and view assertions)
- `node --check` on browser scripts, plus `tests/analytics-consent.test.js`, `tests/pickup-pagination.test.js`, and `tests/consignor-autocomplete.test.js`

`tests/mysql.php` is not part of CI. Run it by hand against a disposable MySQL database after changing a MySQL repository or a migration.
