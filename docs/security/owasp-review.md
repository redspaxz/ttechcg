# OWASP review

A review of the T&Tech website and Pickupsheet application against the OWASP Top 10 (2025). It lists the findings, how each was fixed, and the risks accepted. It covers the code on `main` as of 2026-10-11. It is a code and design review, not a penetration test.

Related: [Technical requirements](../architecture/technical-requirements.md#security-requirements) · [ISO 27001 controls](iso-27001-application-controls.md) · [Support playbook](../support/support-playbook.md)

## Findings and fixes

| # | Severity | Category | Finding | Fix | Status |
|---|---|---|---|---|---|
| 1 | High | A09 Logging and alerting, A08 Integrity | A restore deleted every table in its allowlist, including tables missing from the uploaded file. A file holding only sheets and shipments emptied the security log, the audit tables, users, and MFA. Any admin within 15 minutes of signing in could erase the audit trail | Restore never clears or replaces `pickup_security_events` or `pickup_records_session_activity`. It refuses a file missing any application table. It writes a `started` event to the security log and the PHP error log before changing data. Tested in `tests/mysql.php` | Fixed |
| 2 | Medium | A04 Cryptographic failures | Actor IDs were plain SHA-256 of the username, and client IDs plain SHA-256 of the IP address. Hashing a list of usernames, or all 2³² IPv4 addresses, reverses them | `Pseudonym` computes HMAC-SHA256 keyed with `SECURITY_PSEUDONYM_KEY`, used at every former call site. `bin/rekey-pseudonyms.php` converts stored actor IDs. Without a key the app keeps plain SHA-256 and logs a warning in production | Fixed in code. Production needs the key set and the rekey run (implementation plan, item 2) |
| 3 | Medium | A07 Authentication, A06 Insecure design | The contact-form "captcha" is single-digit arithmetic that a script can solve | The answer is refused if it comes within 3 seconds of the form loading. Docs no longer call it a captcha, and the mark-paid question is described as an intent check, not an access control | Mitigated. See accepted risks |
| 4 | Low | A01 Broken access control | JumpCloud and Cloudflare Access roles are fixed for the session. Removing a user from a group leaves an open session working for up to 8 hours | The playbook has a procedure to end a user's sessions at once | Accepted, with a procedure |
| 5 | Low | A07 Authentication | Unknown usernames were checked against a bcrypt cost-10 dummy hash while real hashes are Argon2id. The timing difference reveals which usernames exist | The dummy hash is built once per process with `PasswordHasher`, the same algorithm and cost as real hashes | Fixed |
| 6 | Low | A07 Authentication | 15 failed logins per account in 15 minutes locks that account, and anyone who knows a username can trigger it | Documented as a trade-off against password guessing spread across many addresses | Accepted |
| 7 | Low | A02 Security misconfiguration | `RecordsAccess::authenticate()` and `Request::basicCredentials()` accepted HTTP Basic credentials but were never routed. A later change could have exposed them | Both removed. Tests sign in through `authenticateCredentials()` | Fixed |
| 8 | Info | A02 Security misconfiguration | HSTS has no `includeSubDomains` | Kept off until every `ttechcg.com` subdomain is confirmed to serve HTTPS | Open decision |

The review also found that a backup holds password hashes and encrypted MFA secrets, while the ISO mapping said "secrets are excluded". The docs are corrected. A backup file and its passphrase are to be handled as credentials.

## Checked and found sound

| Category | Evidence |
|---|---|
| A01 Broken access control | Deny-by-default permission map (`RecordsPrincipal`), checked in every controller action. Database accounts can never hold the admin role. Return links accept only same-site Pickupsheet paths |
| A02 Security misconfiguration | CSP without `unsafe-inline`, frame denial, `nosniff`, COOP and CORP, no-store on protected pages. Production hides errors, exception arguments, and the PHP version. The production `APP_URL` is forced to the canonical HTTPS origin |
| A03 Software supply chain | No Composer or npm runtime dependencies. GitHub Actions pinned to commit SHAs. `bin/security-check.php` blocks committed secrets |
| A04 Cryptographic failures | Argon2id passwords, AES-256-GCM backups with associated data and PBKDF2-SHA256 at 210,000 iterations, encrypted TOTP secrets, hashed single-use recovery codes |
| A05 Injection | Prepared statements throughout, `LIKE` wildcards escaped, output escaped with `htmlspecialchars`. XLSX exports write inline strings, so a value starting with `=` is never a formula. Backup restore checks table and column names against an allowlist and the live schema |
| A06 Insecure design | Server-side totals, stamps, and validation. Payment amount must equal the recalculated total. Paid status cannot be reversed |
| A07 Authentication failures | MFA required for local sign-in in production. JumpCloud OIDC with state, nonce, PKCE S256, and signature, issuer, and audience checks. Session ID renewed at sign-in and every 15 minutes. `__Host-` cookie, strict mode, 1-hour idle and 8-hour absolute limits. Fresh sign-in needed for backup and restore |
| A08 Integrity failures | CSRF synchronizer tokens plus Fetch Metadata and Origin checks. Backups authenticated with GCM |
| A09 Logging and alerting | Structured security events for sign-ins, refusals, and every protected action, with request IDs. Retention bounded to 30–3,650 days |
| A10 Exceptional conditions | Unavailable repository adapters fail closed. Rate-limit store errors return 503, not an unlimited pass. Restore and merges run in transactions |

## Accepted risks and open decisions

1. **Federated sessions** keep their role for up to 8 hours (finding 4). Revisit if staff turnover or policy needs faster revocation.
2. **Bot protection** on the contact form stops casual scripts only. The per-client rate limit bounds a targeted bot. Cloudflare Turnstile is the next step if spam appears, at the cost of needing JavaScript.
3. **Account lockout** by a third party lasts 15 minutes (finding 6).
4. **HSTS subdomains** (finding 8).
5. **Database administrators** can still alter or delete audit rows directly. Forward logs to an external, access-restricted service for immutable evidence (implementation plan, item 8).
6. **Not covered by this review:** hosting configuration, the cPanel account, JumpCloud and Cloudflare tenant settings, and a live penetration test.
