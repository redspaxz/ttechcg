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

Every Pickupsheet screen is protected by the dedicated login portal at `/dhl/pickupsheet/login`, with independently configurable local-account authentication and direct JumpCloud OIDC SSO. Successful local, JumpCloud, and Cloudflare Access sign-ins land on `/dhl/pickupsheet/submissions`; the new-sheet form and administrator dashboard remain available from workspace navigation. JumpCloud derives `admin`, `operator`, or `viewer` from approved groups. The authorization-code flow uses state, nonce, PKCE S256, an exact callback URI, regional issuer allowlisting, signed RS256 ID-token verification against JumpCloud JWKS, UserInfo subject matching, and required verified email/name claims. Accounts without a mapped group fail closed. The exact JumpCloud `name` claim is retained in the protected session, displayed as the account name, and enforced as the read-only Check By value on create and edit. Local accounts retain their separately managed names, passwords, roles, and Active/Inactive status. The administrator account workspace presents managed users in a detailed editable table with alternating row colors, effective sign-in method status, and account timestamps. A `viewer` can create, list, and paginate records but cannot edit, print, or export them. An `operator` can create, list, paginate, print to PDF, export Excel files, and update existing CRM profile details except customer, organization, and contact names; operators cannot edit pickup records, change their status, delete them, create CRM profiles, adjust rewards, or manage access. An `admin` receives the activity dashboard, full CRM management, KPI graph, complete record visibility, edit/print/export access, the open-to-paid action, audited soft deletion, user management, login-frequency reporting, and paginated security and operational events. Sessions expire after one hour idle or eight hours total. Displayed AWB numbers in submitted sheets, CRM shipment history, and printable sheets open the corresponding DHL Cameroon tracking page in a new tab. Excel downloads contain only shipment-detail columns, shipment rows, and a bold shipment-total row; pickup-sheet header metadata and sequential row numbering are excluded. Qualified operational tables use database-backed pagination at 10 rows per page: submitted sheets, CRM customers, customer shipment and redemption histories, dashboard user activity, detailed audit logs, and recent pickup sheets. These tables progressively enhance their normal links with same-origin AJAX fragment loading, per-table spinners, accessible busy states, browser-history support, and independent page state where multiple tables share a screen. Pagination updates in place without automatically moving the viewport. Entry/edit registers, print layouts, and the shipment details inside one pickup sheet remain unpaginated because they represent a single aggregate or form. Successful, forbidden, and denied records access is security-logged using hashed actor, client, target, and record identifiers. Logs do not retain passwords, form contents, raw pickup references, or raw IP addresses. Legacy `/pickupsheet` URLs redirect permanently to the new `/dhl/pickupsheet` location.

The restricted CRM at `/dhl/pickupsheet/customers` synchronizes existing shipment consignors into a searchable customer directory. Operators and administrators can view profiles and maintain business email, phone, Cameroon/Nigeria location data, relationship status, internal notes, and follow-up dates. Customer, organization, and contact names are read-only for operators and are preserved server-side even if a modified request submits replacement values. Administrators can also create prospective customers and change names. Each profile shows shipment count, recorded cash value, first and last shipment dates, and its complete linked-shipment history in 10-row pages. Recorded cargo earns 10 reward points per kilogram, calculated across a customer's active shipment weight at one point per 0.1 kg; any aggregate remainder below 0.1 kg does not earn a fractional point. The loyalty summary distinguishes the redeemable total-points balance from lifetime earned points, which include cargo-weight points and positive bonuses but never decrease after redemption. Lifetime earnings determine the loyalty tier: Bronze at 0–99 points, Silver at 100–249, Gold at 250–499, and Platinum at 500 or more. Administrators can add bonuses or record redemptions with a required reason; the append-only adjustment history preserves pseudonymous actor and UTC time, and atomic validation prevents a redemption from making the balance negative. Each customer profile also shows all redemptions in a dedicated 10-row paginated log with points, reason, UTC timestamp, and pseudonymous administrator identifier. CRM changes use CSRF protection, persistent rate limiting, server validation, and the detailed Pickupsheet security log; viewers fail closed, while operators cannot create profiles, change names, or adjust rewards.

Administrators can use `/dhl/pickupsheet/admin/backup` to create and restore portable encrypted application backups without cPanel CLI access. Backups cover inquiries, pickup sheets and shipments, CRM and rewards, local account password hashes, encrypted authenticator enrollments, recovery-code hashes, saved sign-in method preferences, session-activity history, and application audit records. They exclude `.env`, the 2FA encryption key, database credentials, JumpCloud and Cloudflare secrets, uploaded assets, schema migrations, and PHP session files. Export reads all allowlisted tables inside one consistent MySQL transaction. Each file is encrypted with AES-256-GCM using a unique random salt and IV plus a passphrase-derived PBKDF2-SHA256 key; the passphrase is never stored or logged. Restore requires the encrypted file, its passphrase, CSRF validation, and an exact `RESTORE` confirmation. Table names and columns are allowlisted, uploads are limited to 12 MB, and all deletes and inserts run in one MySQL transaction so a failed restore is rolled back. Restoring local account data may require affected administrators to sign in again. Backup creation and restore outcomes are security-logged without recording the passphrase or backup contents.

## JumpCloud RBAC setup

Create a **Custom OIDC App** in JumpCloud and configure:

- Login URL: `https://ttechcg.com/dhl/pickupsheet/login`
- Redirect URI: `https://ttechcg.com/dhl/pickupsheet/auth/jumpcloud/callback`
- Client authentication: **Client Secret Basic** (or set both sides to `client_secret_post`)
- Standard scopes: **Profile** and **Email**; `openid` is requested automatically
- Group attribute: `groups`
- Authorised groups: `Pickupsheet Admins`, `Pickupsheet Operators`, and `Pickupsheet Viewers`

Copy the client ID and the one-time client secret into the server-managed `.env`, set `JUMPCLOUD_OIDC_ENABLED=true`, then set the matching `JUMPCLOUD_OIDC_*` and `JUMPCLOUD_RBAC_*` values shown in `.env.example`. Use the issuer for the JumpCloud organisation's region: US `https://oauth.id.jumpcloud.com/`, EU `https://oauth.id.eu.jumpcloud.com/`, or India `https://oauth.id.in.jumpcloud.com/`. JumpCloud users must also be authorised to the SSO application; application access is denied by default. Once the configuration validates, the login page displays JumpCloud and, when enabled, the local account option.

OIDC maps identities at sign-in and does not copy JumpCloud users into MySQL. JumpCloud's exact display name is retained only in the protected application session. Group, profile, or account changes take effect on the next login or when the existing Pickupsheet session expires (one hour idle, eight hours absolute). Local accounts remain separately managed in Pickupsheet.

### Login method switches

The two direct portal methods are controlled independently in the server-managed `.env`:

```dotenv
PICKUPSHEET_LOCAL_LOGIN_ENABLED=true
JUMPCLOUD_OIDC_ENABLED=true
```

The `.env` values are hard security limits: a method disabled or incompletely configured there cannot be enabled from the application. Within those limits, an administrator can use the **Sign-in methods** toggles on the user-management page; those operational preferences are stored in MySQL and take effect on new authentication requests immediately. The application prevents both direct methods from being disabled unless a valid Cloudflare Access handoff is configured. Existing authenticated sessions retain their normal idle and absolute expiry; disable or delete an individual local account when immediate account revocation is required. Local login defaults to enabled for backward compatibility; JumpCloud remains unavailable until its full OIDC and RBAC configuration validates. If no method is available, the login route fails closed with HTTP `503`.

### Local account two-factor authentication

Local 2FA uses RFC 6238 six-digit TOTP codes compatible with common authenticator apps. After a correct password, an unenrolled local administrator, operator, or viewer must add the account to an authenticator and confirm a code before a Pickupsheet session is created. Enrollment issues ten one-time recovery codes. Authenticator secrets are encrypted with AES-256-GCM and subject-bound authenticated data; recovery codes are stored only as keyed SHA-256 HMACs. TOTP steps cannot be replayed, recovery codes are consumed transactionally, pending verification expires after five minutes, and both password and code attempts are rate-limited and security-logged without storing codes.

Generate a dedicated key on a trusted local computer, then put it only in the server-managed `.env`:

```bash
openssl rand -base64 32
```

```dotenv
PICKUPSHEET_LOCAL_MFA_ENABLED=true
PICKUPSHEET_MFA_ENCRYPTION_KEY=PASTE_THE_GENERATED_VALUE_HERE
PICKUPSHEET_MFA_ISSUER=T&Tech Pickupsheet
```

Do not reuse a database password or JumpCloud secret as the encryption key. Preserve this key separately from database backups: changing or losing it makes existing authenticator enrollments unreadable, requiring an administrator reset and re-enrollment. JumpCloud and Cloudflare accounts continue to use provider-managed MFA. An administrator can reset a managed local account's 2FA from **Local operators and viewers**; the account must enroll again after its next successful password check. Any signed-in user can open `/dhl/pickupsheet/settings` to review their identity and 2FA status. Local users can replace their own authenticator only after re-entering the current password and a valid existing authenticator or recovery code. The old enrollment remains active until a new TOTP code is confirmed and new recovery codes are issued, so abandoning replacement does not remove account protection.

Sensitive authentication changes follow OWASP defense-in-depth guidance. Managed-user 2FA reset uses a separate confirmation screen: a local administrator must re-enter the current password and their own authenticator or recovery code, while a JumpCloud or Cloudflare administrator must have authenticated within the previous five minutes. Successful authentication and factor changes rotate both the PHP session identifier and synchronizer CSRF token. Active sessions have server-enforced one-hour idle and eight-hour absolute limits and renew their identifier every fifteen minutes. Login throttling is keyed independently to the pseudonymous client and normalized account identifier. Clearly cross-site state-changing requests are rejected from Fetch Metadata or mismatched `Origin`/`Referer` signals before routing; clients that omit those headers still require the existing route-level CSRF token.

New and reset local passwords use Argon2id with 19 MiB memory, two iterations, and one thread when PHP provides it. The compatibility fallback is bcrypt cost 12 and limits passwords to 72 bytes to avoid bcrypt truncation. Successful managed-account login opportunistically upgrades an older password hash. Existing server-defined administrator hashes can be upgraded by running `php bin/generate-records-credentials.php` for a replacement environment entry or by using the authenticated administrator password-reset function.

Application-level ISO/IEC 27001:2022 hardening and operational evidence requirements are documented in [`docs/security/iso-27001-application-controls.md`](docs/security/iso-27001-application-controls.md). The implementation validates Cloudflare forwarding headers against official edge networks, applies security headers at both PHP and Apache boundaries, enforces production-safe PHP error handling, requires operational MFA before local production login is exposed, limits fresh sessions for backup administration, performs defined audit/session retention, protects the deployed `.env`, and runs repository security invariants in CI. These measures support an ISMS but do not by themselves establish ISO certification.

Before deploying this hardening to production, verify that JumpCloud or Cloudflare Access is operational, or set `PICKUPSHEET_LOCAL_MFA_ENABLED=true` with a valid `PICKUPSHEET_MFA_ENCRYPTION_KEY` and MySQL migration 015 available. Otherwise local sign-in intentionally remains unavailable. When cPanel/LiteSpeed restores `REMOTE_ADDR` to the visitor address, the application validates LiteSpeed's server-only `PROXY_REMOTE_ADDR` against the published Cloudflare networks before trusting forwarding headers. If the hosting provider inserts another reverse proxy between Cloudflare and PHP and the connection address is not available, add only that proxy's CIDRs to `CLOUDFLARE_TRUSTED_PROXY_CIDRS`; never add visitor networks or a catch-all CIDR.

### Cloudflare Access SSO handoff

When `/dhl/pickupsheet/*` is protected by a Cloudflare Access self-hosted application that uses JumpCloud, Pickupsheet can consume that existing identity instead of displaying its own login page. In Cloudflare Zero Trust, require the JumpCloud login method for the Access application and ensure the full Access identity exposes the JumpCloud `groups` claim and profile name. Copy the application's **Application Audience (AUD) Tag** and the Zero Trust team domain into the server-managed `.env`, then configure:

```dotenv
CLOUDFLARE_ACCESS_ENABLED=true
CLOUDFLARE_ACCESS_TEAM_DOMAIN=https://ttechconsultgroup.cloudflareaccess.com
CLOUDFLARE_ACCESS_AUDIENCE=e7ab5db07a198e02d5289701a019a815c1988bb228a1009e04df5c411dc58267
CLOUDFLARE_ACCESS_GROUPS_CLAIM=groups
```

The application validates the signed `Cf-Access-Jwt-Assertion` header against Cloudflare's rotating certificates, exact team-domain issuer, application audience, token type, validity times, and verified email. It then retrieves the full Access identity to map the same `JUMPCLOUD_RBAC_*` groups and preserve the JumpCloud display name. Missing, invalid, expired, mismatched, service-account, or unmapped identities fail closed to the normal login portal. The break-glass URL `/dhl/pickupsheet/login?local=1` deliberately displays only the enabled direct login methods without consuming the Access handoff. Signing out a Cloudflare-authenticated session redirects through `/cdn-cgi/access/logout` so the Access cookie is cleared rather than immediately signing the user back in.

Pickupsheet does not apply country or regional access restrictions. Access remains controlled by the configured local, JumpCloud, or Cloudflare Access identity methods and role permissions. Cloudflare IP Geolocation is not required by the application; remove any country-based Cloudflare Access, WAF, or firewall rule if unrestricted global reach is intended.

Google Analytics measurement ID `G-WVFXFB5H3M` is included exactly once immediately after `<head>` through both shared page layouts. Consent Mode starts with analytics and advertising storage denied; analytics storage is granted only after a visitor accepts, advertising consent remains denied, and visitors can reopen their choice from the footer. Before acceptance, Google may receive cookieless consent-state pings but cannot set Analytics cookies through this configuration. Pickup-sheet operational pages suppress Analytics page views and sanitize page location so record-reference query values are not sent to Google. The Content Security Policy permits only the Google Tag Manager script origin and Google Analytics collection origins required by the tag. Because Google's tag is dynamically updated and cannot use a stable Subresource Integrity hash, its narrowly scoped CSP exception is an explicit third-party resource decision.

## Local development

1. Copy `.env.example` to `.env` and adjust the values.
2. Run `php bin/generate-records-credentials.php records-admin admin "First" "Last"` and copy the generated `PICKUPSHEET_RBAC_USERS` line into `.env` to configure a local administrator.
3. Start MySQL with `docker compose up -d`.
4. Run `php -S 127.0.0.1:8080 router.php`.
5. Open `http://127.0.0.1:8080`.

Run the dependency-free checks with:

```shell
php tests/run.php
```

## cPanel deployment

The `.cpanel.yml` recipe targets `/home/ttecwymc/public_html`, making this application the root website. It preserves the server-managed `.env`. Back up the existing WordPress files and database before the first root deployment.

For production records access, configure every enabled authentication method in `/home/ttecwymc/public_html/.env`: a securely hashed local administrator plus a dedicated 2FA encryption key when local login and MFA are enabled, and the OIDC client plus three role groups when JumpCloud is enabled. Also ensure `APP_ENV=production` and normally keep `RUN_MIGRATIONS=false`.

Edit `/home/ttecwymc/public_html/.env` in cPanel File Manager and set `PICKUPSHEET_LOCAL_LOGIN_ENABLED`, `PICKUPSHEET_LOCAL_MFA_ENABLED`, the locally generated `PICKUPSHEET_MFA_ENCRYPTION_KEY`, and `JUMPCLOUD_OIDC_ENABLED` directly; no cPanel CLI is required. Never commit any secret. PHP must have `openssl` for local 2FA and both `curl` and `openssl` for JumpCloud. After saving `.env`, open `/dhl/pickupsheet/login` in a private browser window and complete enrollment for one administrator before closing an existing administrator session.

This release uses migrations `005_create_pickup_records_users.sql` through `015_create_pickup_local_mfa.sql`. Authenticated workflows initialize missing account, audit, lifecycle, administrator-credential, user-name, session-activity, security-event, CRM customer, reward-ledger, sign-in preference, and local 2FA storage when the runtime MySQL user has the required schema permissions, supporting cPanel accounts without CLI access. Login and unauthenticated requests do not create schemas; 2FA enrollment may initialize its table only after a valid local password. If the runtime MySQL user does not have `CREATE` and `ALTER` permission, use cPanel phpMyAdmin to execute migrations `005` through `015`, then keep `RUN_MIGRATIONS=false`.

The deployment normalizes copied directory permissions to `755` and file permissions to `644`. This prevents LiteSpeed from showing a directory listing while returning `404` for files that exist when the cPanel repository checkout has restrictive permissions. After pulling `main` in cPanel Git Version Control, select **Deploy HEAD Commit**. The recipe removes stale entry points and development-only artifacts, installs a readable `.htaccess`, and verifies the PHP entry point and stylesheet before succeeding.
