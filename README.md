# T&Tech Consulting Group

[![PHP 8.2](https://img.shields.io/badge/PHP-8.2-777BB4?logo=php&logoColor=white)](https://www.php.net)
[![MySQL 8](https://img.shields.io/badge/MySQL-8-4479A1?logo=mysql&logoColor=white)](https://www.mysql.com)
[![License](https://img.shields.io/badge/License-Internal-blue)](#)
[![Status](https://img.shields.io/badge/Status-Production--ready-green)](#)

A PHP 8.2 modular monolith that powers the T&Tech corporate website and the protected Pickupsheet operations workspace.

## Screenshot / project banner

```text
╔══════════════════════════════════════════════════════════════════╗
║                      T&Tech Consulting Group                     ║
║       Corporate website + protected Pickupsheet operations       ║
║                                                                  ║
║   Public website   |   CRM & loyalty   |   Admin dashboard       ║
╚══════════════════════════════════════════════════════════════════╝
```

> Replace this banner with a real dashboard or front-end screenshot when the project is deployed and a capture is ready.

## Architecture at a glance

```text
HTTP request
  -> index.php / router.php
  -> bootstrap/app.php
  -> shared HTTP + security boundary
  -> module controller
  -> application service
  -> domain logic / repository contract
  -> MySQL adapter or demo/unavailable adapter
  -> server-rendered view / response
```

## Overview

This project serves two related surfaces in one codebase:

- a public-facing corporate site for services, products, offices, partnerships, and contact enquiries
- a protected internal Pickupsheet workspace for cash-shipment operations, CRM, loyalty, dashboards, and administrator controls

The application uses a dependency-light MVC structure, a single front controller, and MySQL-backed persistence. Protected writes fail closed when required infrastructure is unavailable, while the public site remains readable in degraded or read-only modes.

> Mission: provide a reliable, secure public brand presence and a strict operational workflow for cash-based shipment management that is auditable, role-bound, and easy to recover.

## Repository highlights

- one PHP application with both public and protected business surfaces
- modular codebase organized by domain and shared infrastructure
- MySQL-backed operational data, audit evidence, and backup/restore support
- production-oriented security controls for access, validation, and session management
- support for local identity, JumpCloud SSO, and Cloudflare Access patterns

## Stack

- PHP 8.2+
- MySQL 8 / InnoDB
- Apache or LiteSpeed-compatible front-controller routing
- Native PHP sessions and server-side security controls
- No production bundler required
- JavaScript validation and behavioral checks via the project test suite

### Summary

This project combines a corporate website, secure access workflows, operational pickup records, customer management, loyalty tracking, dashboard reporting, and encrypted operational backup into a single PHP application.

## Requirements

- PHP 8.2 or later
- `pdo_mysql` enabled
- `openssl` enabled
- `curl` enabled
- MySQL 8 or compatible server
- Node.js only for JavaScript validation/testing

## Quick start

### 1. Clone and configure

```bash
git clone <repo-url>
cd ttechcg
cp .env.example .env
```

Update the local `.env` file with your database values and any required identity settings before you start the app.

### 2. Start MySQL locally

```bash
docker compose up -d mysql
```

This uses the local MySQL service defined in `docker-compose.yml` and exposes it on port `3306`.

### 3. Start the PHP app

```bash
php -S 127.0.0.1:8080 -t public
```

Useful local URLs:

- http://127.0.0.1:8080/
- http://127.0.0.1:8080/dhl/pickupsheet/login
- http://127.0.0.1:8080/contact

### 4. Run migrations

Local development automatically runs migrations when the app boots, but you can force them explicitly with:

```bash
RUN_MIGRATIONS=true php -S 127.0.0.1:8080 -t public
```

## Environment configuration

The app reads configuration from `.env` and a runtime config file in `config/app.php`.

Key variables include:

| Variable | Purpose |
|---|---|
| `APP_ENV` | runtime environment like `local` or `production` |
| `APP_DEBUG` | verbose PHP debug mode in non-production |
| `APP_URL` | canonical application URL |
| `APP_TIMEZONE` | default timezone, currently `Africa/Douala` |
| `CONTACT_EMAIL` | recipient for public enquiries |
| `CONTACT_FROM_EMAIL` | approved sender address for mail transport |
| `DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER`, `DB_PASSWORD` | MySQL connection settings |
| `PICKUPSHEET_LOCAL_LOGIN_ENABLED` | enables local workforce sign-in |
| `PICKUPSHEET_LOCAL_MFA_ENABLED` | requires local MFA |
| `PICKUPSHEET_MFA_ENCRYPTION_KEY` | encryption key material for local MFA |
| `JUMPCLOUD_OIDC_*` | JumpCloud SSO configuration |
| `CLOUDFLARE_ACCESS_*` | optional Cloudflare Access configuration |
| `RUN_MIGRATIONS` | explicitly allow migration execution |

Important:

- never commit production secrets or a production `.env`
- keep environment values in the server-managed deployment environment, not in source control

## Project structure

```text
.
├── bootstrap/              # composition root and dependency wiring
├── config/                 # runtime configuration
├── database/
│   └── migrations/         # ordered SQL schema migrations
├── docs/                   # project and security documentation
├── public/                 # web root and static assets
├── src/
│   ├── Modules/
│   │   ├── Backup/
│   │   ├── Contact/
│   │   ├── CRM/
│   │   ├── Pickupsheet/
│   │   └── Site/
│   └── Shared/
├── storage/
│   ├── security/
│   └── sessions/
├── tests/                  # validation and behavioral checks
├── views/                  # server-rendered templates
├── .env.example
├── docker-compose.yml
├── index.php
├── router.php
├── README.md
└── bootstrap/app.php
```

## Main modules

### Public site

Handles public content pages and the enquiry workflow:

- home
- services
- products
- about
- privacy
- contact form

### Pickupsheet

Protected operational workspace for:

- pickup-sheet entry and validation
- search, pagination, and audit history
- print/PDF and Excel export
- customer synchronization and CRM activity
- loyalty and rewards management
- tabbed administrator dashboard and administrative controls
- printable A4 performance reports

### CRM

Customer-facing operational recordkeeping and profile data:

- customer directories and profiles
- organization and contact details
- relationship history and follow-up state
- shipment history and reward summaries

### Backup

Encrypted application-data backup and restore with transactional safety checks.

## Feature matrix

| Area | Capability | Status |
|---|---|---|
| Public site | services, products, about, privacy, offices | Implemented |
| Contact form | enquiry capture with consent, captcha, honeypot, rate limiting | Implemented |
| Authentication | local login, MFA, JumpCloud OIDC, optional Cloudflare Access | Implemented |
| Pickupsheet entry | agent/date sheet creation with 1-50 shipment rows | Implemented |
| Shipment validation | server-side recalculation, AWB, value, weight, destination checks | Implemented |
| Workflow control | open/paid/delete lifecycle and audit logging | Implemented |
| Search and listings | paginated searchable submissions and AJAX fallback | Implemented |
| Printing and export | A4 print view and native XLSX export | Implemented |
| CRM | customer profiles, shipment history, follow-up tracking | Implemented |
| Loyalty | point balances, lifetime totals, tiers, adjustments | Implemented |
| Dashboard | tabbed KPIs, market performance, operational metrics, user activity, security log | Implemented |
| Reporting | printable A4 performance reports with selectable period and sections | Implemented |
| Admin tools | user, MFA, password, backup and restore management | Implemented |
| Security | CSRF, session hardening, encryption, RBAC boundaries | Implemented |

### Administrator market-performance analysis

The administrator dashboard turns first-party Pickupsheet records into operational market intelligence. It includes:

- a rolling 90-day comparison against the immediately preceding 90-day period for shipment volume, recorded cash, cargo weight, and active senders
- period-over-period growth indicators with an explicit new-baseline state when no comparable prior activity exists
- pieces handled, cash per shipment, cash per kilogram, payment conversion, repeat-sender rate, and top-destination concentration
- a complete rolling 12-month activity series, including zero-activity months, with shipment, cash, weight, and unique-sender details
- a ranked destination mix with shipment share, recorded cash, and cargo weight for the eight leading lanes

These indicators use only internal, non-deleted Pickupsheet records and collection dates. They are operational performance measures—not estimates of total logistics-market size, accounting revenue, or external competitor performance. Dashboard access remains restricted to the `admin` role.

### Dashboard tabs

The header, administration links and KPI cards stay visible above folder-style tabs. The tabs are Market analysis, Cash activity, Top senders, Loyalty, User activity, Audit log, Recent sheets and Reports. The open tab is kept in the `?tab=` query parameter. The server renders the requested tab directly, so reloads and shared links open the same section, and AJAX table pagination keeps the parameter. Unknown values fall back to Market analysis. On narrow screens the tab bar scrolls sideways, and the open tab is scrolled into view.

### Mobile layout

The administrator dashboard is built for phones down to 360px wide:

- no card is wider than the screen; wide content such as the 14-day cash chart and the activity tables scrolls sideways inside its own card
- below 620px, administration links, KPI cards, market comparison cards and efficiency indicators use compact two-column grids, and fixed card heights are removed
- the 12-month trend keeps two columns at every phone width, and report period options stay two per row
- stacked dashboard grids use `minmax(0, 1fr)` columns. A plain `1fr` column cannot shrink below its content, so wide content like the chart would stretch the card past the screen edge and clip it

After changing dashboard styles, update the `styles.css?v=` and `app.js?v=` versions in `views/layouts/app.php` and the matching assertions in `tests/run.php`, so browsers load the new assets.

### Performance reports

The Reports tab builds a printable A4 report at `/dhl/pickupsheet/dashboard/report`:

- periods: 30, 90 (default), 180 or 365 days, each compared with the preceding period of the same length
- sections: KPI summary and cash settlement, period-over-period market performance, 12-month activity trend, destination mix, top 10 senders, and customer loyalty leaders
- no section selected, or only invalid values, produces the full report; unsupported periods fall back to 90 days
- the report opens in the print layout with Print / Save as PDF and shows the reporting dates, the preparer and the generation time
- a new `report` permission restricts it to the `admin` role, and each generation is recorded in the security log as records access with action `report`

Cash settlement always covers the last 3 months. Trend, destination, sender and repeat-sender figures always cover a rolling 12 months. The report labels these bases.

## Security and operational expectations

This project is designed with security controls built in:

- CSRF protection for state-changing requests
- server-side validation and authoritative recalculation of business data
- rate limiting and abuse controls
- local account login and optional MFA
- JumpCloud OIDC and optional Cloudflare Access integration
- pseudonymous audit logging
- strict session and cookie configuration
- no secrets in source control or user-facing scripts

## Production deployment checklist

Use this checklist before promoting the application to a production environment:

1. Confirm `.env` is present in the deployment environment and not committed to source control.
2. Set `APP_ENV=production`, `APP_DEBUG=false`, and a correct `APP_URL` with HTTPS.
3. Validate `DB_*` credentials, MySQL availability, and required `pdo_mysql` support.
4. Ensure `openssl`, `curl`, and the required PHP extensions are enabled in the web runtime.
5. Set `CONTACT_EMAIL` and `CONTACT_FROM_EMAIL` to approved production addresses.
6. Configure local login, MFA, and storage keys only if the environment is ready for the required security controls.
7. Configure JumpCloud OIDC and/or Cloudflare Access only after confirming issuer, callback, and RBAC settings.
8. Set `RUN_MIGRATIONS` only for intentional schema updates; keep it disabled for normal production operation.
9. Validate writable permissions for `storage/sessions` and `storage/security`.
10. Confirm the deployment is using HTTPS and secure cookies/session settings.
11. Test backup creation and restore with a safe dataset before production use.
12. Confirm monitoring checks and health routes are active for operational visibility.
13. Run the project validation suite: `php tests/run.php`.
14. Perform a final role-based verification for public, viewer, operator, and admin workflows.

## Validation

Run the project test script:

```bash
php tests/run.php
```

This includes the repository's assertion-based checks for application behavior and security-related logic.

Run the JavaScript behaviour checks with Node.js:

```bash
for test in tests/*.test.js; do node "$test"; done
```

Business user acceptance testing follows the scripts in `docs/uat/`. The current release is covered by [docs/uat/admin-dashboard-reporting-uat.md](docs/uat/admin-dashboard-reporting-uat.md).

## Useful commands

```bash
# Start local MySQL
docker compose up -d mysql

# Run the app locally
php -S 127.0.0.1:8080 -t public

# Force migrations during local testing
RUN_MIGRATIONS=true php -S 127.0.0.1:8080 -t public

# Run checks
php tests/run.php
```

## Troubleshooting

### MySQL connection errors

- confirm `.env` has valid `DB_*` values
- ensure Docker MySQL is running: `docker compose ps`
- test the connection with your local credentials

### App starts but routes fail

- confirm `APP_URL` matches your local environment
- ensure PHP has the required extensions enabled
- check that `.env` exists and is readable

### Migration issues

- set `RUN_MIGRATIONS=true` for local or explicit schema changes
- keep the database in a consistent dev state before testing features that depend on schema changes

### Authentication and SSO issues

- validate `.env` for JumpCloud and Cloudflare settings
- verify that the configured callback URL matches your actual app host
- ensure the local login and MFA settings are valid before enabling them in production

## Production notes

- use HTTPS in production
- keep secrets in the deployment environment, not in the repository
- leave `RUN_MIGRATIONS` off unless you intentionally want schema changes applied
- monitor `/health` and operational dependency checks in deployed environments

## Contributing

Contributions are welcome for bug fixes, quality improvements, and operational hardening.

Before opening a pull request:

1. create a feature branch from the latest default branch
2. keep changes scoped and well documented
3. run the project validation suite: `php tests/run.php`
4. avoid committing `.env`, secrets, or private identity configuration
5. describe the business impact and verification steps in the pull request

## Project status and roadmap

### Current status

- public website shell and content flows are active
- pick-up operation workflow is implemented and validated
- customer, loyalty, and admin capabilities are in place
- security, retention, and access controls are part of the core design

### Planned improvements

- improve operational monitoring and deployment automation
- expand automated regression coverage for edge-case flows
- tighten deployment runbooks and environment provisioning documentation
- review and streamline admin and reporting workflows as operational needs evolve

## Documentation

This README is the developer-facing guide for setup, maintenance, and operational onboarding. The project also contains more detailed product and security documentation under `docs/` and the source code itself:

- [docs/security/iso-27001-application-controls.md](docs/security/iso-27001-application-controls.md): application security controls
- [docs/uat/admin-dashboard-reporting-uat.md](docs/uat/admin-dashboard-reporting-uat.md): UAT script and sign-off for the administrator dashboard, market analysis and reporting
