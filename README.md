# T&Tech Consulting Group

[![PHP 8.2](https://img.shields.io/badge/PHP-8.2-777BB4?logo=php&logoColor=white)](https://www.php.net)
[![MySQL 8](https://img.shields.io/badge/MySQL-8-4479A1?logo=mysql&logoColor=white)](https://www.mysql.com)
[![License](https://img.shields.io/badge/License-Internal-blue)](#)
[![Status](https://img.shields.io/badge/Status-Production--ready-green)](#)
[![Version](https://img.shields.io/badge/Version-1.0.0-informational)](#releases)

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
| `PICKUPSHEET_AWB_REUSE_DAYS` | days before DHL may reissue an AWB number; sets the duplicate-check window and tracking-link expiry (default 90) |

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
- organization and contact details, shown read-only until a user with `crm_update` clicks Edit details (`?mode=edit`); a rejected save reopens the form with the entered values
- a contact activity log (calls, visits, emails, meetings, notes) that also reschedules or closes the follow-up
- customer owners, and directory filters for follow-ups due, owner, and status, with sorting
- Excel export of the filtered directory
- shipment history and reward summaries
- duplicate prevention, reviewed duplicate merges, and merge undo
- customer deletion for erasure requests

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
| Printing and export | A4 print view with PAID watermark, and native XLSX export | Implemented |
| CRM | customer profiles, activity log, owners, follow-up filters, Excel export, duplicate prevention, merge and undo, erasure | Implemented |
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
- the report opens in the print layout with Print / Save as PDF and shows the reporting dates, the preparer and the generation time. Unlike the pickup sheet preview, the report opens the browser print dialog as soon as it loads; its button carries `data-print-auto`
- each section has a server-rendered SVG chart above its table: a paid/unpaid share bar, latest-vs-previous bars for each market metric on its own scale, monthly shipment columns and a separate monthly cash line (never a dual axis), and ranked bars for destinations, senders and loyalty points. Charts need no JavaScript, print in colour, carry hover tooltips and legends, and the tables stay as the accessible table view. Chart markup is built in `src/Modules/Pickupsheet/UI/ReportCharts.php`
- a new `report` permission restricts it to the `admin` role, and each generation is recorded in the security log as records access with action `report`

Cash settlement always covers the last 3 months. Trend, destination, sender and repeat-sender figures always cover a rolling 12 months. The report labels these bases.

### Collection agent

The agent name on a pickup sheet is assigned by an administrator, not typed by the person creating the sheet.

- administrators set it in the **Collection agent** card on Manage users and RBAC (`/dhl/pickupsheet/submissions/users`). The name is 2 to 100 characters, with extra spaces tidied. It is stored in `pickup_sheet_settings` (migration 025, also created on first save), included in encrypted backups, and each change is logged as `pickupsheet.collection_agent_update`
- the new-sheet form shows the assigned name read-only. The server always stamps the assigned name on a new sheet and ignores any `agent_name` sent with the form
- until a name is assigned, the form says "Not assigned", links administrators to the card, disables Save, and the server refuses to create sheets
- an edited sheet keeps the agent it was created with. The record editor shows it read-only, so changing the assigned agent only affects sheets created afterwards

### Payment and receipt confirmation

Marking a sheet paid and correcting its receipt number both happen in a modal on Submitted sheets. Each modal asks a single-use security question loaded from the server when it opens.

- after a successful save, the modal shows only the result: a tick, the title ("Payment confirmed" or "Receipt number updated"), the message and a Close button. The form fields, the security question and the form buttons are hidden
- a failed save shows the error with Try again, which brings the form back
- closing the modal after a successful save reloads the list so the sheet shows its new state

The form and result panel are hidden with the `hidden` attribute. Their `display: grid` rules would override it, so `styles.css` keeps explicit `form[hidden]` and `.pickup-payment-result[hidden]` rules.

### Pickup sheet print view

`/dhl/pickupsheet/submissions/print?reference=…` shows the A4 pickup sheet preview.

- the preview does not open the print dialog by itself. It opens only when Print / Save as PDF is clicked (`public/assets/print.js`)
- a paid sheet shows a large green diagonal "PAID" watermark across the sheet. It is semi-transparent so the shipment details stay readable, and it prints and saves to PDF with the sheet. Unpaid sheets have no watermark
- the back link depends on where the sheet was opened. From Submitted sheets it reads "Back to submitted sheets". From a customer profile it reads "Back to customer profile" (see [CRM operations](#crm-operations))

The print layout loads `print.css` and `print.js` with their own `?v=` versions in `views/layouts/print.php`. Update those and the matching assertions in `tests/run.php` after changing either file.

### Colour system

Pickupsheet uses the 60-30-10 rule, with tokens defined at the top of `public/assets/styles.css`:

| Share | Token | Colour | Used for |
|---|---|---|---|
| 60% | `--pickup-ground`, `--pickup-surface` | off-white `#faf8f2`, white | page backgrounds and cards |
| 30% | `--pickup-structure` with `--pickup-structure-ink` | DHL yellow `#ffcc00` with dark brown `#241b00` text | the sticky header, every table header row, the active dashboard tab, the login side panel |
| 10% | `--pickup-accent` | DHL red `#d40511` | buttons (including Continue with JumpCloud), badges, alerts, the enabled sign-in method toggle, highlight lines |

The login page, the dashboard, the backup page and every workspace page share these tokens. Body text uses the app's browns (`#241b00`, `#493800`, `#655000`), and status colours are green `#0b633a`, red `#d40511` and amber `#8a5a00`. Card subheadings are brown rather than red, which keeps red for actions and alerts. Use the tokens in new styles instead of new colour values.

The login page shows "T&Tech Consulting Group" with "Cash Shipment Management Portal" on its own line underneath, separated by a thin horizontal line.

Every Pickupsheet page, including sign-in, has a footer reading "© {current year} T&Tech Consulting Group. All rights reserved." followed by a Privacy link to the privacy notice at `/privacy`. It is pinned to the bottom of the screen and stays in place while the page scrolls. The `<body>` of Pickupsheet pages carries the `pickup-app` class, which reserves the footer's height (`--pickup-footer-height`) as bottom padding so the footer never covers the end of a page. Below 480px wide the footer can wrap to two lines, so the reserved height rises from 44px to 64px. The public site footer carries the same statement in its normal place at the end of the page. In both, the year comes from `date('Y')` in `views/layouts/app.php`, so it changes automatically each year. Printed sheets and reports have no footer.

### AWB number reuse

DHL recycles AWB numbers after about three months, so an AWB only identifies a shipment within that window around its collection date. The window is `PICKUPSHEET_AWB_REUSE_DAYS` (default 90); confirm the exact period with DHL, and whether it counts from the label date or the collection date.

- the same AWB twice on one sheet is always refused
- an AWB cannot be entered again within the window of its collection date. When a new or edited sheet uses an AWB that is already on another active sheet collected within the window either side of its date, the save is refused with a message naming the other sheet and its collection date, and the number must be corrected. There is no override. Every refusal is recorded in the security log (`pickupsheet.awb_reuse`)
- once a shipment is older than the window, its AWB is shown as plain text marked "tracking expired" instead of a DHL tracking link, on submitted sheets, printed sheets, and customer shipment history, because the link could now open another customer's shipment
- nothing treats the AWB alone as a unique key; identify a shipment by its sheet and line, or by AWB plus collection date

### CRM duplicate customers

One organization should have one customer profile. The CRM enforces this in four places.

**Unique names.** `pickup_customers.display_name` has a unique index. MySQL compares names with `utf8mb4_unicode_ci`, so case, trailing spaces and accents are ignored: "Société Générale" and "societe generale" count as the same name. Migration 018 first folds any existing profiles that break this rule into the oldest one, moving their reward adjustments and filling empty contact fields. Then it adds the index.

**Creating a customer.** On the add-customer form, the name field suggests existing profiles as you type. When the typed name matches an existing profile, or a name merged into one, the form links to that profile. The server also rejects the save and shows the same link.

**Shipment synchronization.** Consignors are grouped with the same collation, so spelling variants on sheets become one profile. Profile keys come from the first name a profile was created with. A renamed profile keeps its key, so a new sender who later uses the old name gets a random key instead of colliding. Autocomplete requests do not synchronize; the CRM pages and the add form do that when they open.

**Renaming.** Renaming a customer updates the consignor on every existing shipment for that customer, on open, paid and deleted sheets. Matching ignores case, accents and surrounding spaces, and the confirmation says how many shipments changed. New sheets collapse repeated spaces in consignor names, and migration 020 collapses them on existing shipments and profiles, folding profiles that differed only by spacing. A spelling that differs in any other way, such as added punctuation, is a separate customer: merge it first so a rename covers its sheets too.

**Reviewed merges.** Administrators see "Possible duplicate customers" on the CRM page when there is at least one suggested pair; with no suggestions the card is not shown:

- every profile is checked. Names are compared only within groups sharing their first or last three letters, after folding accents and expanding `co`, `corp`, `intl` and `ltd`
- a pair needs a name-match score of at least 88. Names that differ in any number, such as "Douala Branch 1" and "Douala Branch 2", are never suggested or merged
- a merge moves shipments, reward adjustments and aliases to the kept profile. Empty contact fields are filled from the other profile; conflicting values and notes are added to the kept profile's notes, cut to 2,000 bytes without splitting a character
- the merged-away name becomes an alias of the kept profile. Shipments later entered under that name are moved to the kept profile at the next synchronization, and the name cannot be used for a new profile
- **Ignore** beside the two Keep buttons marks a suggested pair as different customers. After a confirmation, both profiles stay as they are and the pair is no longer suggested. The pair is stored once with its keys sorted in `pickup_customer_duplicate_dismissals` (migration 024), with who ignored it and when, and is included in encrypted backups. Deleting or merging away either profile removes the row. Ignored pairs can still be merged from a customer profile with Merge a duplicate. Ignoring is rate limited, needs a CSRF token and the `crm` permission, and is recorded in the security log as `pickupsheet.crm_customer_duplicate_dismiss`

**Undo.** "Recent merges" lists merges that can still be undone. Undo:

- recreates the merged profile with its original key and details
- moves its reward adjustments and aliases back
- hands back the shipments the merge moved, identified by sheet and line, if they still name the kept profile
- restores fields on the kept profile that nobody has edited since the merge

**Ignore** sits beside Undo merge. It confirms the merge as final: the merge leaves Recent merges and can no longer be undone (migration 022 records who ignored it and when). Ignoring and undoing are recorded in the security log as `pickupsheet.crm_customer_merge_dismiss` and `pickupsheet.crm_customer_merge_undo`.

Undo will not run when another profile now uses the original name, or when the kept profile has since been merged into another one; undo that later merge first. Shipments entered under the old name after the merge stay with the kept profile.

Merge history is stored in `pickup_customer_merges`, with a JSON snapshot per merge, and aliases in `pickup_customer_aliases` (migration 019). Both tables are included in encrypted backups. Merges and undos are rate limited, need a CSRF token and the `crm` permission, and are recorded in the security log as `pickupsheet.crm_customer_merge` and `pickupsheet.crm_customer_merge_undo`.

### CRM operations

**Profile layout.** A customer profile opens with a summary line under the name (status, owner, next follow-up with an Overdue badge, last shipment) and Call / Email buttons, then Customer details (Organization, Relationship, Primary contact), Activity, shipment figures and history, Reward points, and, for administrators, Delete customer. Activity shows the latest 10 entries with a "Show all" link. Reward points have one paginated Points history of bonuses and redemptions showing who made each change (migration 023 stores the name; older entries fall back to the matching account), and the adjustment form sits behind "Adjust points". Edit details shows only the form, straight under the heading. A thin divider separates the name, summary line and quick actions from the metrics cards below.

**Customer IDs.** Every profile has a readable ID: its database ID padded to six digits, such as `000042`, shown on the profile, in the directory, and in the Excel export. Directory search finds a customer by `000042` or `42`, and still accepts the older `CUS-000042` form found in exports made before version 1.0.0. Formatting and parsing live in `CustomerProfile::referenceFor()` and `CustomerProfile::idFromReference()`.

**Shipment history links.** Clicking a pickup sheet reference in a customer's Recent shipments table opens that sheet's print view. The link carries the customer key and the profile's current shipment and points page numbers and page sizes. "Back to customer profile" returns to the same pages and lands on the Recent shipments section (`#customer-shipments`). The print controller rebuilds the return link itself and drops page values that are not valid.

**Activity and follow-ups.** The Activity section on a customer profile logs calls, visits, emails, meetings and notes with a date and summary (no future dates). The same form sets the next follow-up: change it to reschedule, or clear it to close the follow-up. When a follow-up is scheduled, a **Close follow-up** button also appears on the Next follow-up card and in the overdue alert. After a confirmation it clears the date and adds a note to Activity, such as "Follow-up due 2026-08-30 closed.", so the history shows who closed it and when. It is logged in the security log as `pickupsheet.crm_customer_follow_up_close`. Anyone with `crm_update` can log activity and close follow-ups. The "Follow-ups due" figure on the CRM page links to the matching filtered list.

**Directory.** Filter by search text (including merged-away names), status, follow-up (due, scheduled later, not scheduled) and owner (mine, unassigned), and sort by priority, name, latest shipment, shipment value or reward points. `%` and `_` in the search box are matched literally. "Export to Excel" downloads the filtered list, up to 5,000 customers; it needs the `export` permission and is rate limited and logged.

**Owners.** Administrators assign a customer to themselves or to any active local operator or administrator account; the owner shows on the profile and in the directory. Operator edits keep the existing owner.

**Edit conflicts.** The edit form carries the profile's last-updated time. If someone else saved the profile in the meantime, the save is refused with an explanation, and the form reopens with the entered values.

**Deletion.** Administrators can delete a customer, for example to honour an erasure request. This removes the profile, its activity, reward adjustments, aliases and merge snapshots. Pickup sheets keep the consignor name as part of the operational record, and the profile is not rebuilt from those sheets; it only comes back if the name is saved on a sheet again.

**Rewards.** Bonuses are always accepted. If points were redeemed on a sheet that was later deleted, the profile shows a points shortfall instead of a negative balance, and new bonuses cover the shortfall first. Redemptions are checked against the visible balance.

**Audit trail.** Every consignor change the CRM makes on pickup sheets (renames, merges, merge undos, and resolving merged-away names on new sheets) is written to `pickup_sheet_edit_audit` per sheet, tagged with its source.

**Pickup-sheet suggestions.** The consignor suggestions on pickup sheets include CRM customers that have no sheets yet, so operators pick the recorded spelling.

**Performance.** CRM pages only turn shipment rows added since the previous run into customer profiles (tracked in `pickup_crm_sync_state`), and single-profile lookups total that customer's shipments through the consignor index added in migration 021.

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
8. Set `RUN_MIGRATIONS` only for intentional schema updates; keep it disabled for normal production operation. Back up the database before migration 018, which merges existing CRM profiles whose names are equal under the database collation.
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

Run the MySQL integration checks for the CRM and pickup-sheet SQL against a disposable database. The script drops every table in the target database, so it refuses any database whose name does not end in `_test`, and it skips itself when `TEST_MYSQL_DSN` is not set:

```bash
TEST_MYSQL_DSN="mysql:host=127.0.0.1;port=3306;dbname=pickupsheet_test;charset=utf8mb4" TEST_MYSQL_USER=root TEST_MYSQL_PASSWORD=secret php tests/mysql.php
```

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
- migration 021 adds the owner columns only when they are missing, because the application adds them itself if a CRM page loads first
- to preview which CRM profiles migration 018 will fold together, run `SELECT display_name, COUNT(*) FROM pickup_customers GROUP BY display_name HAVING COUNT(*) > 1;` before migrating

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

### Releases

| Version | Date | Git tag | Summary |
|---|---|---|---|
| 1.0.0 | 2026-10-01 | `v1.0.0` | First versioned release of the corporate website and the Pickupsheet workspace |

**1.0.0** brings together everything described in this README, including:

- pickup sheet entry, validation, AWB reuse protection, payment and receipt confirmation, and the A4 print view with a PAID watermark
- the tabbed administrator dashboard, market-performance analysis and printable performance reports
- the CRM: customer profiles with six-digit customer IDs, activity and follow-ups with Close follow-up, owners, Excel export, duplicate prevention, reviewed merges with Ignore and undo, and erasure
- loyalty points, encrypted backup and restore, local sign-in with two-factor authentication, and JumpCloud SSO
- one colour system across the login page, dashboard and workspace pages, and a footer with the current year

Later releases are tagged `vMAJOR.MINOR.PATCH` and listed here. To check out this release, run `git checkout v1.0.0`.

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
