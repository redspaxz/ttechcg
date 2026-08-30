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

Every Pickupsheet screen is protected by the dedicated login portal at `/dhl/pickupsheet/login`, which offers both local account authentication and JumpCloud OIDC SSO. JumpCloud derives `admin`, `operator`, or `viewer` from approved groups. The authorization-code flow uses state, nonce, PKCE S256, an exact callback URI, regional issuer allowlisting, signed RS256 ID-token verification against JumpCloud JWKS, UserInfo subject matching, and required verified email/name claims. Accounts without a mapped group fail closed. The exact JumpCloud `name` claim is retained in the protected session, displayed as the account name, and enforced as the read-only Check By value on create and edit. Local accounts retain their separately managed names, passwords, roles, and Active/Inactive status. A `viewer` can create, list, and paginate records but cannot edit, print, or export them. An `operator` can create, list, paginate, print to PDF, and export Excel files, but cannot edit records, change their status, delete them, or manage access. An `admin` receives the activity dashboard, KPI graph, complete record visibility, edit/print/export access, the open-to-paid action, audited soft deletion, user management, login-frequency reporting, and the latest 50 detailed security and operational events. Sessions expire after one hour idle or eight hours total. Displayed AWB numbers in submitted sheets, CRM shipment history, and printable sheets open the corresponding DHL Cameroon tracking page in a new tab. Excel downloads contain only shipment-detail columns, shipment rows, and a bold shipment-total row; pickup-sheet header metadata and sequential row numbering are excluded. The records view uses database-backed pagination at 10 sheets per page, with progressively enhanced AJAX loading, a visible spinner, browser-history support, and standard links as a no-JavaScript fallback. Successful, forbidden, and denied records access is security-logged using hashed actor, client, target, and record identifiers. Logs do not retain passwords, form contents, raw pickup references, or raw IP addresses. Legacy `/pickupsheet` URLs redirect permanently to the new `/dhl/pickupsheet` location.

The administrator-only CRM at `/dhl/pickupsheet/customers` synchronizes existing shipment consignors into a searchable customer directory. Administrators can add prospective customers, maintain primary contact details, Cameroon/Nigeria location data, relationship status, internal notes, and follow-up dates. Each profile shows shipment count, recorded cash value, first and last shipment dates, and the latest 20 linked shipments. Every active shipment earns one reward point automatically. Administrators can add bonuses or record redemptions with a required reason; the append-only adjustment history preserves pseudonymous actor and UTC time, and atomic validation prevents a redemption from making the balance negative. CRM changes use CSRF protection, persistent rate limiting, server validation, and the detailed Pickupsheet security log; viewers and operators fail closed.

## JumpCloud RBAC setup

Create a **Custom OIDC App** in JumpCloud and configure:

- Login URL: `https://ttechcg.com/dhl/pickupsheet/login`
- Redirect URI: `https://ttechcg.com/dhl/pickupsheet/auth/jumpcloud/callback`
- Client authentication: **Client Secret Basic** (or set both sides to `client_secret_post`)
- Standard scopes: **Profile** and **Email**; `openid` is requested automatically
- Group attribute: `groups`
- Authorised groups: `Pickupsheet Admins`, `Pickupsheet Operators`, and `Pickupsheet Viewers`

Copy the client ID and the one-time client secret into the server-managed `.env`, set `JUMPCLOUD_OIDC_ENABLED=true`, then set the matching `JUMPCLOUD_OIDC_*` and `JUMPCLOUD_RBAC_*` values shown in `.env.example`. Use the issuer for the JumpCloud organisation's region: US `https://oauth.id.jumpcloud.com/`, EU `https://oauth.id.eu.jumpcloud.com/`, or India `https://oauth.id.in.jumpcloud.com/`. JumpCloud users must also be authorised to the SSO application; application access is denied by default. Once the configuration validates, the login page displays both JumpCloud and local account options.

OIDC maps identities at sign-in and does not copy JumpCloud users into MySQL. JumpCloud's exact display name is retained only in the protected application session. Group, profile, or account changes take effect on the next login or when the existing Pickupsheet session expires (one hour idle, eight hours absolute). Local accounts remain separately managed in Pickupsheet.

### Cloudflare Access SSO handoff

When `/dhl/pickupsheet/*` is protected by a Cloudflare Access self-hosted application that uses JumpCloud, Pickupsheet can consume that existing identity instead of displaying its own login page. In Cloudflare Zero Trust, require the JumpCloud login method for the Access application and ensure the full Access identity exposes the JumpCloud `groups` claim and profile name. Copy the application's **Application Audience (AUD) Tag** and the Zero Trust team domain into the server-managed `.env`, then configure:

```dotenv
CLOUDFLARE_ACCESS_ENABLED=true
CLOUDFLARE_ACCESS_TEAM_DOMAIN=https://ttechconsultgroup.cloudflareaccess.com
CLOUDFLARE_ACCESS_AUDIENCE=e7ab5db07a198e02d5289701a019a815c1988bb228a1009e04df5c411dc58267
CLOUDFLARE_ACCESS_GROUPS_CLAIM=groups
```

The application validates the signed `Cf-Access-Jwt-Assertion` header against Cloudflare's rotating certificates, exact team-domain issuer, application audience, token type, validity times, and verified email. It then retrieves the full Access identity to map the same `JUMPCLOUD_RBAC_*` groups and preserve the JumpCloud display name. Missing, invalid, expired, mismatched, service-account, or unmapped identities fail closed to the normal local/JumpCloud login portal. The break-glass URL `/dhl/pickupsheet/login?local=1` deliberately displays the local and direct JumpCloud options without consuming the Access handoff. Signing out a Cloudflare-authenticated session redirects through `/cdn-cgi/access/logout` so the Access cookie is cleared rather than immediately signing the user back in.

Pickupsheet is restricted to requests geolocated by Cloudflare to Cameroon (`CM`) or Nigeria (`NG`); the public corporate site remains globally available. In Cloudflare Zero Trust, create a reusable Access rule group with one **Include / Country** rule containing **Cameroon** and **Nigeria**, then add that group as a **Require / Rule group** rule on the existing Pickupsheet Allow policy. Keep the JumpCloud identity or group requirement in the same Allow policy. Also enable **Network > IP Geolocation** (or the visitor-location Managed Transform) so Cloudflare sends `CF-IPCountry` to the origin. The application applies the same country allowlist to `/dhl/pickupsheet/*` and legacy `/pickupsheet/*` routes and fails closed when that header is absent, malformed, or outside the allowlist in production. These server-managed settings make the policy explicit:

```dotenv
PICKUPSHEET_GEO_RESTRICTION_ENABLED=true
PICKUPSHEET_ALLOWED_COUNTRIES=CM,NG
```

Do not add Cameroon and Nigeria as separate **Require / Country** rules: Require rules use AND logic. Use one country rule group so the two countries are evaluated with OR logic.

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

For production records access, configure JumpCloud OIDC, the three role groups, and at least one securely hashed local administrator in `/home/ttecwymc/public_html/.env`. Also ensure `APP_ENV=production` and normally keep `RUN_MIGRATIONS=false`. The login page offers either JumpCloud SSO or a local account.

Edit `/home/ttecwymc/public_html/.env` in cPanel File Manager and paste the OIDC values directly; no cPanel CLI is required. Never commit the client secret. PHP must have `curl` and `openssl` enabled. After saving `.env`, open `/dhl/pickupsheet/login` in a private browser window and test both sign-in methods.

This release uses migrations `005_create_pickup_records_users.sql` through `013_create_pickup_customer_rewards.sql`. Authenticated workflows initialize missing account, audit, lifecycle, administrator-credential, user-name, session-activity, security-event, CRM customer, and reward-ledger storage when the runtime MySQL user has the required schema permissions, supporting cPanel accounts without CLI access. Login and unauthenticated requests cannot create schemas. If the runtime MySQL user does not have `CREATE` and `ALTER` permission, use cPanel phpMyAdmin to execute migrations `005` through `013`, then keep `RUN_MIGRATIONS=false`.

The deployment normalizes copied directory permissions to `755` and file permissions to `644`. This prevents LiteSpeed from showing a directory listing while returning `404` for files that exist when the cPanel repository checkout has restrictive permissions. After pulling `main` in cPanel Git Version Control, select **Deploy HEAD Commit**. The recipe removes stale entry points and development-only artifacts, installs a readable `.htaccess`, and verifies the PHP entry point and stylesheet before succeeding.
