# ISO/IEC 27001 application control mapping

## Status and scope

This document records security measures implemented by the T&Tech website and Pickupsheet application. It is an engineering control mapping—not an ISO/IEC 27001 certificate, Statement of Applicability, risk register, internal audit, or management review. Certification requires an organization-wide, risk-based information security management system and an appropriately scoped independent audit.

The application scope includes source code, GitHub verification, cPanel deployment, PHP sessions, MySQL application data, local and federated identities, security events, and encrypted application backups. Hosting operations, personnel screening, endpoint management, physical security, supplier agreements, business impact analysis, and organization-wide incident governance remain T&Tech responsibilities outside this repository.

The governing references are the licensed standards. Public ISO summaries describe ISO/IEC 27001:2022 as the ISMS requirements standard and ISO/IEC 27002:2022 as implementation guidance for information-security controls:

- <https://www.iso.org/standard/27001>
- <https://www.iso.org/standard/75652.html>

## Implemented technical controls and evidence

| Control area | Application measure | Evidence |
| --- | --- | --- |
| Access and identity | Deny-by-default RBAC; managed-user status; local, JumpCloud, and Cloudflare identities; required first and last names; production local login is unavailable unless local MFA and its encrypted storage are operational. | `RecordsPrincipal`, `RecordsAccess`, login-method service, local MFA tests, user-management audit events |
| Authentication information | Argon2id password hashing with a hardened bcrypt fallback; encrypted TOTP seeds; one-time recovery-code hashes; account and client throttling; session and CSRF rotation. | `PasswordHasher`, `LocalMfaService`, `RecordsSession`, authentication regression tests |
| Privileged operations | Admin-only user, backup, restore, delete, and RBAC functions; fresh authentication is required for backup access; managed-user MFA reset requires administrator reauthentication. | Controller authorization checks and `pickupsheet.records_access` / change events |
| Network trust | Forwarded client IP, country, and request identifiers are accepted only when the direct peer belongs to an official Cloudflare edge network. Direct-origin callers cannot spoof those headers to bypass throttling or country policy. | `CloudflareRequestTrust`, `Request`, country-policy tests |
| Web and application security | Synchronizer CSRF tokens, Fetch Metadata and origin validation, input bounds, prepared SQL, output encoding, CAPTCHA, upload limits, CSP, HSTS, frame denial, MIME sniffing prevention, restricted referrers and browser permissions. | Application boundary, `.htaccess`, controllers, rendering and security regression tests |
| Cryptography and backup | AES-256-GCM authenticated backup encryption, unique salt and IV, passphrase-derived key, allowlisted schema, transactional restore, explicit confirmation, and security logging. Secrets are excluded from backups. | Backup service/controller tests and backup audit events |
| Logging and monitoring | UTC structured security events use request, pseudonymous client, actor, target, resource, outcome, role, and provider fields. PHP error logs provide a second operational stream. Response request IDs support correlation. | `SecurityLogger`, MySQL security-event repository, dashboard audit view |
| Retention and deletion | Audit events and session-activity data are deleted daily after the approved configured retention window; defaults are 365 days and bounds are 30–3650 days. Business/customer retention requires a separate approved schedule. | `SecurityDataRetention`, server `.env`, retention event in PHP logs |
| Secure configuration | Production suppresses browser error details and exception arguments, logs errors, hides the PHP signature, forces secure host-only session cookies, blocks sensitive paths/files, disables indexes and content negotiation, and restricts request size. | Bootstrap, `.htaccess`, deployment verification |
| Change and supply-chain control | Read-only workflow permissions, commit-pinned Actions, PHP/JavaScript validation, tests, tracked-secret detection, and cPanel descriptor validation gate changes. | `.github/workflows/verify.yml`, `bin/security-check.php`, Git history |

## Required operational routine

The control owner should retain dated evidence outside the production web root:

1. Review active administrators, operators, viewers, JumpCloud groups, and Cloudflare policies quarterly and immediately after role changes or departures.
2. Review failed authentication, forbidden access, account changes, backup/restore activity, and unexpected retention failures at least weekly; investigate material anomalies promptly.
3. Create encrypted backups on the approved schedule, store them separately from the hosting account and passphrase, and complete a documented restore test at least quarterly.
4. Review Cloudflare's published IPv4 and IPv6 edge ranges during each quarterly security review and before changing proxy or DNS architecture.
5. Apply PHP, MySQL, cPanel, Cloudflare, JumpCloud, and dependency security updates under the approved vulnerability and change-management process.
6. Record incidents with UTC timestamps, owner, scope, evidence preserved, containment, eradication, recovery, notifications, and lessons learned.
7. Review the data-retention periods against customer, contractual, privacy, tax, and Cameroon/Nigeria legal requirements before changing them.
8. Re-run the risk assessment and this mapping after material architecture, supplier, identity, data, or regulatory changes.

## Residual risks and decisions

- cPanel/hosting administrators and database administrators remain capable of altering application files or MySQL audit rows. Forward PHP/security logs to an access-restricted external log service when stronger immutability is required.
- Cloudflare and JumpCloud authentication assurance, availability, log retention, and privileged administration depend on their tenant configuration and supplier controls.
- The 15-minute backup gate measures the age of the application session. Federated identity-provider step-up and phishing-resistant MFA must be enforced in the JumpCloud/Cloudflare policy because the application cannot force reauthentication of an existing Cloudflare Access session.
- The application validates current published Cloudflare networks, but those ranges can change; direct-origin access should also be restricted at the hosting firewall when the provider supports it. If the hosting stack places another trusted reverse proxy between Cloudflare and PHP, list only that proxy's CIDRs in `CLOUDFLARE_TRUSTED_PROXY_CIDRS`.
- Customer and shipment retention is intentionally not automated because an approved legal/business retention schedule has not been supplied.
- A successful CI run shows that defined automated checks passed for one commit; it does not replace peer review, risk acceptance, penetration testing, internal audit, or certification.
