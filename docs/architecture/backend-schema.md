# Backend schema

The MySQL schema as it stands after migrations 001–025, table by table. For what the data means, the field rules, and retention, see [data requirements](data-requirements.md). For the entity diagram, see [data requirements: data model](data-requirements.md#data-model).

Related: [Technical requirements](technical-requirements.md) · [App flows](flows.md) · [Product requirements](../product/product-requirements.md)

## Conventions

- **Engine and charset.** InnoDB, `utf8mb4`, `utf8mb4_unicode_ci` on every table. Unique indexes on names therefore ignore case, accents, and trailing spaces.
- **Time.** `DATETIME` values are written in UTC (`UTC_TIMESTAMP()` or server code) and shown in `APP_TIMEZONE`. `DATE` and `TIME` on sheets are local collection values.
- **Money.** Whole XAF in `BIGINT UNSIGNED`. There are no decimals.
- **Actors.** `*_by` and `actor_id` columns hold `CHAR(24)`: the first 24 hex characters of SHA-256 of the username. They are pseudonymous, and names are stored separately only where they are shown (`actor_name`, `assigned_name`).
- **Customer keys.** `CHAR(64)` hex. The key is the SHA-256 of the lowercased first name of the profile, or SHA-256 of that key plus a UUID when the key was already taken.
- **Soft delete.** Only `pickup_sheets` is soft-deleted (`deleted_at`). Everything else is deleted for real, and child rows are removed by `ON DELETE CASCADE`.
- **Migrations.** `bin/migrate.php` (or `RUN_MIGRATIONS=true`) applies `database/migrations/*.sql` in filename order and records each in `schema_migrations`. Several repositories also run `CREATE TABLE IF NOT EXISTS` on first use, so a missing table is created on demand. The migrations remain the source of truth.

## Migration log

| # | Change |
|---|---|
| 001–002 | `inquiries`, then privacy consent columns |
| 003 | `pickup_sheets`, `pickup_shipments` |
| 004 | `pickup_sheets.reference_number` (legacy rows backfilled `PS-YYYYMMDD-LEGACY-id`) |
| 005 | `pickup_records_users` |
| 006 | `pickup_sheet_edit_audit` |
| 007 | Sheet lifecycle columns (`status`, `paid_*`, `deleted_*`), `pickup_sheet_lifecycle_audit` |
| 008 | `pickup_records_admin_credentials` |
| 009 | `pickup_records_users.first_name`, `last_name` |
| 010 | `pickup_records_session_activity` |
| 011 | `pickup_security_events` |
| 012 | `pickup_customers` |
| 013 | `pickup_customer_reward_adjustments` |
| 014 | `pickup_auth_settings` (seeded row 1, both methods on) |
| 015 | `pickup_local_mfa` |
| 016 | `pickup_customers.assigned_role`, `country_code` defaults to `CM` |
| 017 | `pickup_sheets.payment_receipt_number` |
| 018 | Folds profiles with equal names, then makes `display_name` unique. **Back up first** |
| 019 | `pickup_customer_aliases`, `pickup_customer_merges` |
| 020 | Collapses spaces in consignors, names, and aliases, and folds profiles that differed only by spacing. **Back up first** |
| 021 | `pickup_customer_activities`, customer owner columns, `pickup_crm_sync_state`, consignor index |
| 022 | Merge dismissal columns |
| 023 | `pickup_customer_reward_adjustments.actor_name` |
| 024 | `pickup_customer_duplicate_dismissals` |
| 025 | `pickup_sheet_settings` |

## Pickup sheets

### `pickup_sheets`

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| `id` | BIGINT UNSIGNED | no | auto | PK |
| `reference_number` | VARCHAR(48) | no | | Unique. `PS-YYYYMMDD-` + 32 hex |
| `agent_name` | VARCHAR(100) | no | | Assigned collection agent at creation |
| `collection_date` | DATE | no | | |
| `shipment_count` | SMALLINT UNSIGNED | no | | 1–50 |
| `total_cash_received_xaf` | BIGINT UNSIGNED | no | | Server-calculated sum |
| `currency` | CHAR(3) | no | `XAF` | |
| `privacy_consent_at` | DATETIME | no | | |
| `privacy_notice_version` | VARCHAR(20) | no | | e.g. `2026-10-03` |
| `created_at` | DATETIME | no | now | |
| `status` | VARCHAR(20) | no | `open` | `open` or `paid` |
| `paid_at` | DATETIME | yes | | |
| `paid_by` | CHAR(24) | yes | | Actor hash |
| `payment_receipt_number` | VARCHAR(64) | yes | | Uppercase |
| `deleted_at` | DATETIME | yes | | Soft delete |
| `deleted_by` | CHAR(24) | yes | | Actor hash |

Indexes: `reference_number` (unique), `collection_date`, `agent_name`, `created_at`, `(status, deleted_at)`, `deleted_at`.

### `pickup_shipments`

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| `id` | BIGINT UNSIGNED | no | auto | PK. Also the CRM sync cursor |
| `pickup_sheet_id` | BIGINT UNSIGNED | no | | FK → `pickup_sheets.id`, cascade |
| `line_number` | SMALLINT UNSIGNED | no | | 1-based |
| `consignor` | VARCHAR(160) | no | | Uppercase. Matches a customer by name |
| `awb_number` | VARCHAR(20) | no | | 8–20 digits. Not unique on its own |
| `destination` | CHAR(3) | no | | Uppercase code |
| `amount_xaf` | BIGINT UNSIGNED | no | | |
| `pieces` | SMALLINT UNSIGNED | no | | |
| `weight_kg` | DECIMAL(10,3) UNSIGNED | no | | |
| `collection_time` | TIME | no | | |
| `checked_by` | VARCHAR(100) | no | | Checker account name, uppercase |
| `created_at` | DATETIME | no | now | |

Indexes: `(pickup_sheet_id, line_number)` (unique), `awb_number`, `destination`, `consignor`.

### `pickup_sheet_edit_audit`

| Column | Type | Null | Notes |
|---|---|---|---|
| `id` | BIGINT UNSIGNED | no | PK |
| `pickup_sheet_id` | BIGINT UNSIGNED | no | FK → `pickup_sheets.id`, cascade |
| `reference_number` | VARCHAR(48) | no | |
| `actor_id` | CHAR(24) | no | |
| `before_snapshot`, `after_snapshot` | LONGTEXT | no | JSON of the sheet. CRM changes are tagged with their source |
| `created_at` | DATETIME | no | |

Index: `(reference_number, created_at)`.

### `pickup_sheet_lifecycle_audit`

Same shape as the edit audit, plus `action` VARCHAR(20): `paid`, `receipt_edit`, or `delete`. `after_snapshot` is null for deletes. Indexes: `(reference_number, created_at)`, `(action, created_at)`.

### `pickup_sheet_settings`

Single row (`settings_id = 1`): `collection_agent_name` VARCHAR(100) NOT NULL default `''`, `updated_by` CHAR(24), `created_at`, `updated_at` (on update).

## CRM

### `pickup_customers`

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| `id` | BIGINT UNSIGNED | no | auto | PK. Shown as a six-digit customer ID |
| `customer_key` | CHAR(64) | no | | Unique. Stable across renames |
| `display_name` | VARCHAR(160) | no | | Unique |
| `contact_name` | VARCHAR(100) | yes | | |
| `email` | VARCHAR(254) | yes | | Lowercase |
| `phone` | VARCHAR(32) | yes | | `+237 XXX XXX XXX` |
| `address` | VARCHAR(255) | yes | | |
| `city` | VARCHAR(100) | yes | | |
| `country_code` | CHAR(2) | no | `CM` | |
| `status` | VARCHAR(20) | no | `active` | `lead`, `active`, `attention`, `inactive` |
| `notes` | TEXT | yes | | Up to 2,000 bytes |
| `next_follow_up_on` | DATE | yes | | |
| `source` | VARCHAR(20) | no | `manual` | `manual`, or `shipment` when created by sync |
| `assigned_role` | VARCHAR(20) | no | `admin` | |
| `assigned_actor_id` | CHAR(24) | yes | | Owner |
| `assigned_name` | VARCHAR(160) | yes | | Owner display name |
| `created_by`, `updated_by` | CHAR(24) | yes | | |
| `created_at` | DATETIME | no | now | |
| `updated_at` | DATETIME | no | now, on update | Used for edit-conflict checks |

Indexes: `customer_key` (unique), `display_name` (unique), `(status, next_follow_up_on)`, `email`, `assigned_actor_id`.

### `pickup_customer_reward_adjustments`

`id` PK, `customer_key` CHAR(64) FK → `pickup_customers.customer_key` (cascade), `points_delta` INT (positive bonus, negative redemption), `reason` VARCHAR(255), `actor_id` CHAR(24), `actor_name` VARCHAR(160) NULL, `created_at`. Indexes: `(customer_key, created_at)`, `(actor_id, created_at)`.

### `pickup_customer_activities`

`id` PK, `customer_key` FK (cascade), `activity_type` VARCHAR(20), `occurred_on` DATE, `summary` TEXT, `actor_id` CHAR(24), `actor_name` VARCHAR(160), `created_at`. Index: `(customer_key, occurred_on)`.

### `pickup_customer_aliases`

`id` PK, `customer_key` FK (cascade), `alias_name` VARCHAR(160) unique, `created_by` CHAR(24) NULL, `created_at`. Index: `customer_key`.

### `pickup_customer_merges`

`id` PK, `target_customer_key` and `source_customer_key` CHAR(64), `target_display_name` and `source_display_name` VARCHAR(160), `snapshot` MEDIUMTEXT (JSON used by undo), `merged_by` CHAR(24), `merged_at`, `undone_by` and `undone_at` NULL, `dismissed_by` and `dismissed_at` NULL. Indexes: `(undone_at, merged_at)`, `target_customer_key`. There are no foreign keys. Deleting a customer removes the merges that name it in code.

### `pickup_customer_duplicate_dismissals`

`id` PK, `first_customer_key` and `second_customer_key` (sorted, both FK cascade), `dismissed_by`, `dismissed_at`. Unique `(first_customer_key, second_customer_key)`, index on `second_customer_key`.

### `pickup_crm_sync_state`

`sync_name` VARCHAR(40) PK, `last_shipment_id` BIGINT UNSIGNED default 0, `updated_at`.

## Accounts and security

### `pickup_records_users`

`id` PK, `username` VARCHAR(100) unique, `first_name` and `last_name` VARCHAR(49), `password_hash` VARCHAR(255), `role` VARCHAR(20), `active` TINYINT(1) default 1, `created_by` and `updated_by` CHAR(24), `created_at`, `updated_at`. Index: `(role, active)`.

### `pickup_records_admin_credentials`

`username` VARCHAR(100) PK, `password_hash`, `updated_by`, `created_at`, `updated_at`. This stores a changed password for an administrator defined in the environment (`PICKUPSHEET_RBAC_USERS` or the legacy single-admin variables).

### `pickup_local_mfa`

`subject_id` VARCHAR(128) PK, `username` VARCHAR(100) (indexed), `secret_envelope` TEXT (encrypted TOTP secret), `recovery_code_hashes` LONGTEXT, `last_used_step` BIGINT NULL (replay guard), `enabled_at`, `updated_by`, `updated_at`.

### `pickup_auth_settings`

Single row (`settings_id = 1`): `local_login_enabled`, `jumpcloud_login_enabled` TINYINT(1) default 1, `updated_by`, `created_at`, `updated_at`.

### `pickup_records_session_activity`

`activity_id` CHAR(32) PK, `username`, `full_name` VARCHAR(100), `role`, `identity_provider` VARCHAR(32), `logged_in_at`, `last_seen_at`, `logged_out_at` NULL. Indexes: `(username, logged_in_at)`, `(logged_out_at, last_seen_at)`, `logged_in_at`. Pruned after `SESSION_ACTIVITY_RETENTION_DAYS`.

### `pickup_security_events`

| Column | Type | Notes |
|---|---|---|
| `id` | BIGINT UNSIGNED | PK |
| `event_name` | VARCHAR(100) | e.g. `pickupsheet.record_paid` |
| `outcome` | VARCHAR(30) | `accepted`, `denied`, `rate_limited`, `failed`, ... |
| `actor_id`, `target_id`, `resource_id` | CHAR(24) NULL | Hashes |
| `role`, `identity_provider` | VARCHAR NULL | |
| `request_id` | VARCHAR(100) | |
| `client_id` | CHAR(64) | Hashed client identifier |
| `request_method`, `request_path` | VARCHAR | |
| `context_json` | LONGTEXT NULL | Non-secret details |
| `occurred_at` | DATETIME | |

Indexes: `occurred_at`, `(actor_id, occurred_at)`, `(event_name, outcome)`. Pruned after `SECURITY_EVENT_RETENTION_DAYS`.

## Public site

### `inquiries`

`id` PK, `name` VARCHAR(100), `email` VARCHAR(160), `company` VARCHAR(140) NULL, `service` VARCHAR(40), `message` TEXT, `privacy_consent_at` DATETIME NULL, `privacy_notice_version` VARCHAR(20) NULL, `created_at`. Indexes: `email`, `service`, `created_at`.

## Housekeeping

### `schema_migrations`

`migration` VARCHAR(190) PK (filename), `applied_at`. Created by `MigrationRunner`.

### Outside the database

| Store | Location | Holds |
|---|---|---|
| PHP sessions | `storage/sessions` | Signed-in identity, CSRF token, flash messages, CRM profile origin |
| Rate-limit and retention state | `storage/security` | Request counters per scope and client, and the last retention run |
| Demo data | `$_SESSION` | Demo repositories, used locally and in tests only |
