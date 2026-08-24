# T&Tech Consulting Group

The corporate website for `ttechcg.com`, built as a dependency-light PHP 8.2 modular monolith with an MVC HTTP boundary and MySQL persistence.

## Modules

```text
src/
├── Modules/
│   ├── Site/         # Corporate home, services, about, and health routes
│   ├── Contact/      # Inquiry domain, application service, adapters, and controller
│   └── Pickupsheet/  # Dedicated product presentation
└── Shared/           # HTTP kernel, views, security, environment, and database
```

The site uses MySQL through `pdo_mysql`. Valid database settings enable persistent contact inquiries. When MySQL is unavailable, the public site remains reviewable with a session-backed adapter. Database migrations run idempotently on application boot, which supports cPanel accounts without terminal access.

Production contact submissions require both a working MySQL connection and a valid `CONTACT_EMAIL`. Successful inquiries are stored first and then sent to that address through the hosting account's PHP mail transport. If either dependency is missing, the form is disabled and `/health` returns `503` instead of presenting a false success. Set `CONTACT_FROM_EMAIL` to a same-domain mailbox authorised by the hosting account.

## Local development

1. Copy `.env.example` to `.env` and adjust the values.
2. Start MySQL with `docker compose up -d`.
3. Run `php -S 127.0.0.1:8080 router.php`.
4. Open `http://127.0.0.1:8080`.

Run the dependency-free checks with:

```shell
php tests/run.php
```

## cPanel deployment

The `.cpanel.yml` recipe targets `/home/ttecwymc/public_html`, making this application the root website. It preserves the server-managed `.env`. Back up the existing WordPress files and database before the first root deployment.
