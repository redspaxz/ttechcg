# Support playbook

How to handle Pickupsheet support requests and incidents: who acts, how fast, what to check, and the fix for each common problem. It covers the website contact form and the Pickupsheet workspace at `/dhl/pickupsheet`.

Related: [App flows](../architecture/flows.md) · [Technical requirements](../architecture/technical-requirements.md) · [Security policy](../../SECURITY.md) · [ISO 27001 controls](../security/iso-27001-application-controls.md)

## Who does what

| Role | Who | Can do |
|---|---|---|
| First line | Supervisor (operator) on shift | Answer how-to questions, check a sheet's status, confirm payments, collect the details below, escalate |
| Second line | Pickupsheet administrator | Everything in the app: users, MFA resets, sign-in methods, collection agent, edits, deletes, receipts, backups |
| Third line | Hosting and database administrator | `.env`, server files, MySQL, PHP error log, restoring service |
| Identity owner | JumpCloud and Cloudflare administrator | Group membership, SSO and Access policies |

Name each owner, with a phone number and a backup person, in the team's on-call sheet. Keep that sheet outside this repository.

## Severity

The response targets are suggestions. The business sets the final values.

| Severity | Examples | Respond | Who |
|---|---|---|---|
| **S1 Critical** | Workspace down for everyone, suspected breach or data leak, cash records wrong or missing, restore went wrong | 15 minutes, any time | Second and third line together. Security incidents follow [Security incident](#security-incident) |
| **S2 High** | One branch cannot save sheets, nobody can sign in with one method, payments cannot be confirmed | 1 hour in business hours | Second line |
| **S3 Normal** | One user locked out, a wrong receipt number, a duplicate customer | Same business day | First or second line |
| **S4 Low** | How-to questions, report wording, feature ideas | Within 3 business days | First line, then record in the [implementation plan](../plans/implementation-plan.md) |

## Collect this first

Ask for these before escalating, so nobody has to go back to the user:

1. **Who.** The username and role. Never ask for a password, authenticator code, recovery code, or backup passphrase.
2. **When.** The time the problem happened, with the time zone. The app shows Africa/Douala time, and the logs use UTC (Douala time is UTC+1).
3. **Where.** The page address and, for a sheet, its full reference (`PS-YYYYMMDD-…`).
4. **What.** The exact message on screen. A screenshot is best.
5. **Request ID.** For a blank or error page, the `X-Request-ID` response header. On a desktop browser this is in the developer tools under Network. It matches the `request_id` in the security log.

## Quick health checks

| Check | How | Healthy |
|---|---|---|
| Site up | Open `https://ttechcg.com/health` | `{"status":"ok"}` with status 200. `degraded` with 503 means the database is unreachable |
| Sign-in works | Open `/dhl/pickupsheet/login` | The page lists the enabled methods. "No direct sign-in method is enabled" means both switches are off |
| Sheets save | An admin opens a new sheet | No "No collection agent has been assigned" banner |
| Security log | Dashboard, then the Audit log tab | Recent events are present. A sudden run of `denied` or `rate_limited` events needs a look |

## Common requests

| Symptom or message | Cause | Fix | Who |
|---|---|---|---|
| Forgot password (local account) | | Admin: **Manage users and RBAC**, edit the account, enter a new password of 12 or more characters twice. Give it to the user in person or by phone, never in the same message as the username | Second line |
| Forgot password (server-defined admin) | | Another admin uses **Admin password** on the same page. If no admin can sign in, third line generates a new hash with `bin/generate-records-credentials.php` and updates `PICKUPSHEET_RBAC_USERS` in `.env` | Second or third line |
| Forgot password (JumpCloud user) | Password is held by JumpCloud | Identity owner resets it in JumpCloud | Identity owner |
| Lost authenticator (viewer or operator) | | Admin: **Manage users and RBAC**, **Reset 2FA** on the account. Confirm with your own password and code. The user enrols again at next sign-in | Second line |
| Lost authenticator, still has a recovery code | | The user signs in with a recovery code, then replaces the authenticator under **User settings** | User |
| Lost authenticator and all recovery codes (server-defined admin) | Admins cannot reset another admin's MFA in the app | Third line deletes the row in `pickup_local_mfa` where `subject_id = 'local-env:<username>'`, after confirming the person's identity outside the app. They enrol again at next sign-in. Record it as an S3 change | Third line |
| "Too many login attempts. Please try again later." | 10 attempts per 15 minutes from one address, or 15 per account | Wait 15 minutes. Repeated lockouts of one account by someone else are a possible attack: check the audit log for `rate_limited` events and escalate as a security incident | First line |
| Signed out unexpectedly | Sessions end after 1 hour idle or 8 hours in total | Sign in again. Expected behaviour | First line |
| "Local sign-in is temporarily unavailable" | In production, local sign-in needs MFA to be enabled and its key valid | Third line checks `PICKUPSHEET_LOCAL_MFA_ENABLED=true`, `PICKUPSHEET_MFA_ENCRYPTION_KEY`, and the PHP error log | Third line |
| "JumpCloud could not verify this account or its Pickupsheet role." | Usually the user is in none of the three Pickupsheet groups. Otherwise the JumpCloud set-up failed: check the PHP error log | Identity owner adds them to the viewer, operator, or admin group. They then sign in again | Identity owner |
| Role change not showing for a JumpCloud user | The role is read at sign-in | The user signs out and in again | User |
| "No collection agent has been assigned…" and Save is disabled | No agent is set | Admin: **Manage users and RBAC**, **Collection agent**, enter the name | Second line |
| "AWB … is already on another pickup sheet collected within 90 days…" | The AWB is on another active sheet in the reuse window | Open the linked sheet. If it is a typing mistake, correct the number. If the same shipment really was entered twice, an admin deletes the wrong sheet. A real reissue inside the window cannot be saved: escalate to second line with both references | First line, then second |
| "Amount is incorrect. It does not match the pickup sheet amount." | The receipt amount must equal the sheet total exactly | Check the receipt. If the sheet itself is wrong, an admin edits the sheet first, then payment is confirmed | First line, then second |
| Wrong receipt number on a paid sheet | | Admin: **Edit receipt** on the sheet in Submitted sheets. The old number stays in the audit | Second line |
| A sheet was marked paid by mistake | Paid cannot be undone by design | Do not edit the database. Record the facts. The admin may delete the sheet and re-enter it as open, with the reason in the team log | Second line |
| A sheet was deleted by mistake | The app has no undelete | Third line restores it. See [Database-only fixes](#database-only-fixes) | Third line |
| Two CRM profiles for one customer | Created before the duplicate rules, or names differ by more than case, accent, or spacing | Admin: review **Possible duplicate customers** in the CRM, or open the profile and use **Merge a duplicate**. Merges can be undone from **Recent merges** | Second line |
| "A customer with this name already exists" | Names are unique, ignoring case, accents, and spacing | Follow the link to the existing profile | First line |
| Contact form: "Please complete the human verification…" | Wrong answer, an expired question (15 minutes), or answered within 3 seconds | Reload the page, wait a moment, and answer again | Visitor |
| Contact form enquiries not arriving by email | Mail transport | Enquiries are still stored. Third line checks `CONTACT_EMAIL`, `CONTACT_FROM_EMAIL`, and the server mail log | Third line |
| Backup page says a sign-in within 15 minutes is required | Backup and restore need a fresh sign-in | Sign out and sign in again, then go straight to **Backup** | Second line |

## Remove access now

For a departure, a lost phone, or a suspected compromised account.

1. **Local viewer or operator.** Admin: **Manage users and RBAC**, **Disable** the account. Its open sessions end on their next request.
2. **Server-defined admin.** Third line removes the entry from `PICKUPSHEET_RBAC_USERS` in `.env`. Open sessions end on their next request.
3. **JumpCloud or Cloudflare Access user.** The identity owner removes them from the Pickupsheet groups, which blocks new sign-ins. **An open session keeps its role for up to 8 hours.** To end it at once, third line deletes that user's session files:

   ```bash
   cd /path/to/app/storage/sessions
   grep -l 'user@example.com' sess_*        # check what matches first
   grep -l 'user@example.com' sess_* | xargs rm --
   ```

   In an emergency, deleting every `sess_*` file signs everyone out.
4. Record the change, with the time in UTC, in the team log.

## Database-only fixes

These need third line, a fresh backup first, and a record in the team log afterwards, because they bypass the app's audit.

| Task | Steps |
|---|---|
| Undelete a sheet | `UPDATE pickup_sheets SET deleted_at = NULL, deleted_by = NULL WHERE reference_number = '<reference>';` Check that no AWB on it now clashes with a newer sheet. Dashboards and CRM totals pick it up on the next page load |
| Reset a server-defined admin's MFA | `DELETE FROM pickup_local_mfa WHERE subject_id = 'local-env:<username>';` |
| Rekey actor identifiers after setting `SECURITY_PSEUDONYM_KEY` | From the cPanel repository clone (`bin/` is not in `public_html`): `php bin/rekey-pseudonyms.php --env=/home/ttecwymc/public_html/.env`, then the same with `--apply` |

Never edit `pickup_security_events`, the audit tables, or a paid sheet's amount or status in the database.

## Data requests

| Request | Action |
|---|---|
| Erase a customer | Admin deletes the CRM profile. That removes contact details, activities, aliases, merge snapshots, and points. Shipment rows on pickup sheets keep the consignor name, because they are the operational cash record. Tell the requester this |
| Correct a customer's details | Operator or admin edits the CRM profile. Renaming updates the consignor on every past sheet, with audit |
| Copy of a customer's data | Admin exports the CRM directory filtered to that customer, plus the printed sheets that name them. Send only that customer's rows |
| Erase a website enquiry | Third line deletes the row from `inquiries`. Enquiries have no retention period yet (see the [implementation plan](../plans/implementation-plan.md)) |
| Staff member leaves | Follow [Remove access now](#remove-access-now). Their name stays on the sheets they checked, as part of the record |

Record every request, its date, and what was done, outside the app.

## Backup and restore

1. Backups need an admin who signed in within the last 15 minutes. Use a passphrase of 16 or more characters, and store it apart from the file. A lost passphrase means a lost backup.
2. A backup contains password hashes and encrypted MFA secrets. Store it as you would a password vault, outside the hosting account.
3. Before a restore, take a fresh backup of the current state.
4. Restore needs the file, its passphrase, and typing `RESTORE`. It refuses files from a different schema version, and files missing any table.
5. After a restore:
   - Open **Manage users and RBAC** straight away. A restore brings back the accounts and passwords in the backup, including people removed since it was made. Disable anyone who should not have access.
   - Check that recent sheets and payments are as expected.
   - The security log and session activity are **not** replaced, so the audit trail from before the restore is still there, with a `pickupsheet.backup_restore` `started` event and its outcome.
6. Restoring onto a new server also needs the original `PICKUPSHEET_MFA_ENCRYPTION_KEY` and `SECURITY_PSEUDONYM_KEY`. Without them, MFA enrolments fail and actor names in the audit log show as unknown.

## Security incident

Treat these as S1: a suspected account takeover, unexpected admin activity, a run of `denied` or `rate_limited` events from many addresses, a lost backup file, a leaked `.env`, or a report under the [security policy](../../SECURITY.md).

1. **Contain.** [Remove access now](#remove-access-now) for any affected account. If an admin account may be compromised, also switch off local sign-in or JumpCloud under **Sign-in methods**, whichever was used.
2. **Preserve evidence before changing anything.**
   - Copy the PHP error log off the server.
   - Take an encrypted backup. It includes the security log.
   - Note the request IDs and UTC times.
   - Do not restore a backup until the evidence is copied. A restore keeps the security log, but replaces accounts and business data.
3. **Rotate secrets** if `.env` or the server may be exposed:
   - `DB_PASSWORD`
   - `JUMPCLOUD_OIDC_CLIENT_SECRET`
   - the admin password hashes
   - backup passphrases

   Changing `PICKUPSHEET_MFA_ENCRYPTION_KEY` makes every user enrol MFA again. Changing `SECURITY_PSEUDONYM_KEY` breaks the link to older audit entries, so change it only if it leaked.
4. **Assess.** Use the dashboard audit log and session activity: what was accessed or changed, by which actor, and when.
5. **Recover**, then record the incident: UTC times, owner, scope, evidence kept, containment, fix, any notifications made, and lessons learned. Personal-data breaches may need to be notified under Cameroon law. Ask the privacy adviser on the day.

## Changes and releases

Support does not deploy. Report defects to second line with the details from [Collect this first](#collect-this-first). Fixes follow "How to deliver each change" in the [implementation plan](../plans/implementation-plan.md#how-to-deliver-each-change). Back up before any release that includes a migration.
