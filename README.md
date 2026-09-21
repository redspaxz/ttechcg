# T&Tech Consulting Group

A PHP 8.2 modular monolith for the T&Tech corporate website and the protected Pickupsheet operations workspace.

## Overview

This project serves two related surfaces in one codebase:

- a public-facing corporate site for services, products, offices, partnerships, and contact enquiries
- a protected internal Pickupsheet workspace for cash-shipment operations, CRM, loyalty, dashboards, and administrator controls

The application uses a dependency-light MVC structure, a single front controller, and MySQL-backed persistence. Protected writes fail closed when required infrastructure is unavailable, while the public site remains readable in read-only or degraded modes.

## Stack

- PHP 8.2+
- MySQL 8 / InnoDB
- Apache or LiteSpeed-compatible front-controller routing
- Native PHP sessions and server-side security controls
- No production bundler required
- JavaScript validation and behavioral checks via the project test suite

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
- dashboards and administrative controls

### CRM

Customer-facing operational recordkeeping and profile data:

- customer directories and profiles
- organization and contact details
- relationship history and follow-up state
- shipment history and reward summaries

### Backup

Encrypted application-data backup and restore with transactional safety checks.

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

## Validation

Run the project test script:

```bash
php tests/run.php
```

This includes the repository's assertion-based checks for application behavior and security-related logic.

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

## Documentation

This README is meant to be the developer-facing guide for setup and maintenance. The project also contains more detailed product and security documentation under `docs/` and the source code itself.
