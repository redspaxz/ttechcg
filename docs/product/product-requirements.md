# Product requirements

**Product:** T&Tech corporate website and Pickupsheet, the cash-shipment management portal
**Owner:** T&Tech Consulting Group
**Status:** Live. Version 1.0.0 released 2026-10-01, with later changes unreleased (see the [implementation plan](../plans/implementation-plan.md))

Related: [App flows](../architecture/flows.md) · [Technical requirements](../architecture/technical-requirements.md) · [Backend schema](../architecture/backend-schema.md) · [Design brief](../design/design-brief.md)

## Problem

T&Tech collects cash for DHL shipments from walk-in customers in Cameroon. Paper pickup sheets made it hard to:

- prove which shipments were collected, by whom, and for how much
- match collected cash to a payment receipt, and show that a sheet was settled
- stop the same AWB number being recorded twice
- recognise repeat customers, follow up with them, and reward loyalty
- give management a reliable view of volume, cash, and customer trends

The company also needs a public website that presents its services and takes enquiries.

## Goals

1. Every cash shipment is recorded once, on a sheet with a unique reference, by a known staff member.
2. Every sheet is either open or settled against a receipt for the exact amount, and settled sheets cannot be reopened.
3. Every change to a sheet after submission is attributable and auditable.
4. One customer has one CRM profile, built automatically from the sheets.
5. Administrators can see performance and produce printable reports without spreadsheets.
6. The system is secure enough for cash records and personal data: role-based, audited, and recoverable from encrypted backups.

## Non-goals

- Accounting, invoicing, or integration with DHL booking systems
- Online payment or customer self-service
- Estimating the total market size or competitor performance. Dashboards use internal records only
- Countries other than Cameroon (phone numbers, currency XAF)

## Users

| User | Role | Needs |
|---|---|---|
| Counter staff | Viewer | Enter today's sheet quickly on a phone or desktop, then see submitted sheets |
| Supervisor | Operator | Everything a viewer does, plus confirm payments, print and export sheets, and maintain customer details |
| Manager | Admin | Correct or delete sheets, change receipts, manage users and sign-in, assign the collection agent, run dashboards, reports, and backups, and merge, delete, or reward customers |
| Website visitor | Public | Learn about T&Tech services and send an enquiry |

## Functional requirements

Status: **Done** means it is in production, and **Unreleased** means it is merged after 1.0.0 and waiting for the next tag.

### Public website

| ID | Requirement | Status |
|---|---|---|
| PUB-1 | Home, services, products, about, and privacy pages, with office locations in Bamenda and Douala | Done |
| PUB-2 | Contact form: name, email, optional company, service area, and a 20–2,000 character message, with explicit privacy consent | Done |
| PUB-3 | Contact abuse protection: captcha, honeypot field, and 10 submissions per hour per client | Done |
| PUB-4 | Enquiries are stored and emailed to `CONTACT_EMAIL` | Done |
| PUB-5 | Optional analytics, loaded only after consent | Done |

### Sign-in and access

| ID | Requirement | Status |
|---|---|---|
| AUTH-1 | Local sign-in with username and password, which an admin can switch on or off | Done |
| AUTH-2 | Two-factor authentication (authenticator app) for local accounts, with one-time recovery codes and admin reset | Done |
| AUTH-3 | JumpCloud single sign-on with roles from JumpCloud groups, which an admin can switch on or off | Done |
| AUTH-4 | Optional Cloudflare Access sign-in that skips the login page | Done |
| AUTH-5 | Three roles (viewer, operator, admin), with each action checked on the server | Done |
| AUTH-6 | Sessions end after 1 hour idle or 8 hours in total | Done |
| AUTH-7 | Admins create, edit, deactivate, and delete local viewer and operator accounts. Administrator accounts are defined in the server environment | Done |

### Pickup sheets

| ID | Requirement | Status |
|---|---|---|
| PS-1 | A sheet holds 1–50 shipments: consignor, AWB, destination, amount, pieces, weight. Time and checker are filled in by the server | Done |
| PS-2 | The collection agent is assigned by an admin and stamped on every new sheet. Sheets cannot be saved until one is assigned | Done |
| PS-3 | Only admins can choose a collection date other than today | Done |
| PS-4 | Totals are recalculated on the server, and each sheet gets a unique reference | Done |
| PS-5 | An AWB cannot repeat on a sheet, or on another active sheet within the reuse window (default 90 days) | Done |
| PS-6 | Consignor autocomplete from earlier sheets and CRM customers | Done |
| PS-7 | Submitted sheets list with search, pagination, and Open/Closed status | Done |
| PS-8 | Mark paid with a receipt number and an amount that must equal the total, behind a security question. This cannot be undone | Done |
| PS-9 | Admins correct receipt numbers, edit sheets, and delete sheets, each with before/after audit | Done |
| PS-10 | A4 print and PDF with a PAID watermark, and Excel export | Done |
| PS-11 | Consignor names on submitted sheets link to the customer's CRM profile | Unreleased |

### Names and data quality

| ID | Requirement | Status |
|---|---|---|
| DQ-1 | Consignor, customer, contact, agent, and checker names are stored in uppercase | Unreleased |
| DQ-2 | Those names allow only letters, digits, spaces, and hyphens | Unreleased |
| DQ-3 | Repeated spaces are collapsed so one sender maps to one customer | Done |

### CRM and loyalty

| ID | Requirement | Status |
|---|---|---|
| CRM-1 | Customer profiles are created automatically from shipments, and can be added by hand | Done |
| CRM-2 | Customer names are unique, ignoring case, accents, and spacing. Duplicates are refused with a link to the existing profile | Done |
| CRM-3 | Directory with search (name, contact, email, city, merged name, customer ID, phone), filters, sorting, and Excel export | Done. Phone search ignoring spacing is Unreleased |
| CRM-4 | Profile with details, shipment history, activity log, follow-ups, and owner | Done |
| CRM-5 | Renaming a customer renames their consignor on every past sheet, with audit | Done |
| CRM-6 | Duplicate suggestions, reviewed merge, undo, and ignore | Done |
| CRM-7 | Delete a customer for an erasure request | Done |
| CRM-8 | Points at 10 per kg, bonuses and redemptions, and Bronze/Silver/Gold/Platinum tiers | Done |
| CRM-9 | A "New" badge for customers younger than 15 days | Unreleased |
| CRM-10 | The profile's back link returns to the page it was opened from | Unreleased |

### Dashboard, reports, and administration

| ID | Requirement | Status |
|---|---|---|
| ADM-1 | Tabbed dashboard: market analysis, cash activity, top senders, loyalty, user activity, audit log, recent sheets, reports | Done |
| ADM-2 | Printable A4 performance report for 30, 90, 180, or 365 days, with selectable sections | Done |
| ADM-3 | Encrypted backup download and restore | Done |
| ADM-4 | A security log of sign-ins, refusals, and every protected action | Done |
| ADM-5 | A separate Pickupsheet privacy notice in an in-app modal | Unreleased |

## Non-functional requirements

| Area | Requirement |
|---|---|
| Security | Server-side validation and permission checks, CSRF protection, rate limits, hashed passwords, encrypted MFA secrets and backups, pseudonymous logs. Details in the [technical requirements](../architecture/technical-requirements.md#security-requirements) |
| Privacy | Consent recorded with the notice version. Personal data minimised in logs. Erasure for CRM profiles. Retention in the [data requirements](../architecture/data-requirements.md#personal-data-and-retention) |
| Reliability | Writes fail closed when the database is unavailable. Backup and restore are transactional |
| Usability | Works on phones from 360px. Every page works without JavaScript |
| Auditability | Before/after snapshots for edits, payments, receipt changes, deletes, and CRM renames and merges |
| Maintainability | No framework or runtime dependencies. CI runs lint, a security scan, and tests on every push |

## Success measures

These can be tracked from the dashboard and security log. Targets are for the business to set.

- Share of sheets settled (paid) within the reporting period
- AWB reuse refusals per month (should fall as staff adapt)
- Repeat-sender rate and active customers per period
- Sheets edited after submission (lower means better first-time accuracy)
- Failed sign-ins and rate-limit events (unexpected spikes need investigation)

## Assumptions and open questions

1. **AWB reuse window.** DHL is assumed to reissue AWB numbers after about 90 days. Confirm the exact period with DHL, and whether it runs from the label date or the collection date.
2. **Existing names.** Names saved before the uppercase and hyphen-only rule are not rewritten. Decide whether to backfill them (see the implementation plan).
3. **Contact enquiry retention.** Enquiries are never deleted automatically. A retention period should be set.
4. **Account names.** Staff first and last names may contain `.` and `'`. Those are removed only when the name is stamped onto a sheet as the checker. Decide whether account names should follow the same rule.
