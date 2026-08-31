# Security policy

## Reporting a vulnerability

Report suspected vulnerabilities privately to `info@ttechcg.com` with the subject **Pickupsheet security report**. Include the affected URL or component, reproduction steps, potential impact, and a safe contact method. Do not include live passwords, recovery codes, private keys, customer shipment data, or destructive proof-of-concept payloads.

T&Tech should acknowledge a report, assign an incident owner, preserve relevant UTC audit evidence, assess impact, and coordinate remediation before public disclosure. Public GitHub issues are not appropriate for undisclosed vulnerabilities.

## Supported version

Security fixes apply to the current `main` branch and the production deployment sourced from it. Older commits and independent forks are not supported.

## Repository safeguards

- Production secrets belong only in the server-managed `.env`; deployment excludes that file and sets it to mode `600`.
- Pull requests and pushes run PHP linting, application regression tests, browser-script tests, deployment-descriptor validation, and repository secret/integrity checks.
- GitHub Actions dependencies are pinned to full commit hashes.
- Authentication, authorization, backup, restore, customer changes, and record lifecycle operations generate UTC security evidence without recording passwords, MFA seeds, recovery codes, or backup passphrases.

See [the ISO/IEC 27001 application control mapping](docs/security/iso-27001-application-controls.md) for scope, evidence, operational review intervals, and residual responsibilities.
