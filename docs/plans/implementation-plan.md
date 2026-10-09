# Implementation plan

What has been built, what is merged but not yet released, and the next pieces of work, with the steps and checks for each. Requirement IDs refer to the [product requirements](../product/product-requirements.md).

## Delivered

The phases overlapped. Dates are from the first to the last related commit.

| Phase | When | Scope |
|---|---|---|
| 1. Website | Aug 2026 | Corporate site, contact form with consent, captcha, honeypot, and rate limit (PUB-1–5) |
| 2. Pickup sheets | Aug 2026 | Sheet entry and validation, submitted list, print and export, named user identities (PS-1, PS-4, PS-6, PS-7, PS-10) |
| 3. Access and security | Aug–Oct 2026 | Local login with MFA, JumpCloud SSO, Cloudflare Access, RBAC, security log, retention (AUTH-1–7, ADM-4) |
| 4. Lifecycle and audit | Aug–Sep 2026 | Open/paid/delete, receipts with a security question, edit audit, AWB reuse protection (PS-5, PS-8, PS-9) |
| 5. CRM and loyalty | Aug–Oct 2026 | Profiles from shipments, duplicate prevention, merges with undo, activities, owners, points and tiers (CRM-1–8) |
| 6. Dashboard and reports | Aug–Oct 2026 | Tabbed dashboard, market analysis, A4 reports, encrypted backup and restore (ADM-1–3) |
| 7. Release 1.0.0 | 2026-10-01 | Collection agent assignment (PS-2), mobile layout, colour system, tag `v1.0.0` |

## Unreleased (merged after `v1.0.0`)

- Closed status for paid sheets, Privacy link in the footer, return to Submitted sheets after saving
- Workspace Menu button on mobile, one-line sheet references, square modal corners
- Separate Pickupsheet privacy notice in a modal (ADM-5), with notice version `2026-10-03`
- New-customer badge (CRM-9)
- Consignor links to CRM profiles (PS-11), uppercase and hyphen-only names (DQ-1, DQ-2), phone search on digits (CRM-3)
- Profile back link returns to its origin (CRM-10)
- Architecture, product, design, and plan documentation

## Next work

Ordered by priority. Each item lists its steps and the check that shows it is done.

### 1. Release 1.1.0

**Why:** About 20 user-facing changes since `v1.0.0` are live on `main` without a release record.

1. Add a 1.1.0 row and summary to the Releases table in `README.md`.
2. Run the full checks (`php tests/run.php`, `php bin/security-check.php`, and the Node tests).
3. Tag `v1.1.0` and push the tag.

**Done when:** `git tag` lists `v1.1.0` and the README describes it.

### 2. Decide on legacy names

**Why:** Names saved before DQ-1 and DQ-2 are still mixed case and may contain symbols, so lists show both styles.

1. **Uppercase backfill.** This is safe under `utf8mb4_unicode_ci` and cannot create new duplicates. Add migration `026_uppercase_names.sql` to uppercase `pickup_shipments.consignor`, `checked_by`, `pickup_sheets.agent_name`, `pickup_sheet_settings.collection_agent_name`, `pickup_customers.display_name`, `contact_name`, and `pickup_customer_aliases.alias_name`.
2. **Symbols.** Do not strip them in a migration. Removing them can make two profiles equal and break the unique index. Instead, list profiles whose names contain symbols (a CRM filter or a report). Let an administrator rename or merge each one, which already updates the sheets with audit.
3. Back up before running the migration, then run `tests/mysql.php` against a copy of production data.

**Done when:** no stored name has lowercase letters, and the symbol list is empty or accepted by the business.

### 3. Run MySQL tests in CI

**Why:** `tests/mysql.php` covers the real SQL (collation, `REGEXP_REPLACE`, the phone search `REPLACE` chain) but runs only by hand.

1. Add a `mysql:8.4` service to `.github/workflows/verify.yml`.
2. Run `php bin/migrate.php` and then `php tests/mysql.php` with `DB_*` pointing at the service.

**Done when:** a pull request that breaks a MySQL query fails CI.

### 4. Set a contact enquiry retention period

**Why:** `inquiries` are never pruned, unlike security events and session activity.

1. Agree a period with the business (for example 24 months).
2. Add `INQUIRY_RETENTION_DAYS` to `config/app.php` and `.env.example`, bounded the same way as the other retention settings.
3. Prune `inquiries` in `SecurityDataRetention`, and add a test.
4. Update the public privacy notice and the [data requirements](../architecture/data-requirements.md#personal-data-and-retention).

**Done when:** enquiries older than the period are removed by the daily retention run.

### 5. Fix the font stack

**Why:** `styles.css` asks for Inter but never loads it, so the design depends on each device's fonts.

Choose one option:

- **Load Inter.** Self-host the font files in `public/assets/fonts`, They are already allowed by the Content-Security-Policy's `default-src 'self'`.
- **Drop Inter.** Commit to the system font stack and remove `Inter` from `styles.css`.

Either way, bump `styles.css?v=` and check the 360px and print layouts.

**Done when:** every device renders the same font, or the brief says system fonts are intended.

### 6. Align account names with the name rule

**Why:** Account first and last names allow `.` and `'`. Those are removed only when the name is stamped on a sheet, so a sheet can show a different checker name from the account.

1. Decide whether account names should follow DQ-2.
2. If so, apply `NameText` in `RecordsUserService::name()`, and list existing accounts that need renaming.

**Done when:** the checker on new sheets matches the account name exactly.

### 7. Confirm the AWB reuse window

**Why:** The 90-day default is an assumption (product requirements, open question 1).

1. Confirm the period with DHL, and whether it counts from the label date or the collection date.
2. Set `PICKUPSHEET_AWB_REUSE_DAYS` in production, and record the answer in the README.

**Done when:** the production value matches DHL's written answer.

### 8. Monitoring and deployment

Carried over from the README roadmap:

- an external uptime check on `/health`
- an alert on bursts of `denied` or `rate_limited` security events
- a deployment runbook covering `.cpanel.yml`, migrations, backup before migrating, and rollback

**Done when:** an outage or a sign-in attack raises an alert without anyone watching the dashboard.

## How to deliver each change

1. Branch from `main`. Keep one change per commit, with the T&Tech attribution line.
2. Update the code, tests, and the affected documents in `docs/` together.
3. Bump the asset `?v=` values when CSS or JS changes.
4. Run all checks locally, push, and wait for CI.
5. Back up the database before deploying any migration.
