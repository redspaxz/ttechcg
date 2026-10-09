# Application flows

How a request moves through the application, how staff sign in, how a pickup sheet moves from entry to payment, how shipments become CRM customers, and how enquiries, users, and backups are handled. Each diagram is followed by the steps it shows and the rules that apply.

Related: [Product requirements](../product/product-requirements.md) · [Technical requirements](technical-requirements.md) · [Data requirements](data-requirements.md) · [Backend schema](backend-schema.md)

## 1. Request architecture

```mermaid
flowchart TD
    A["Browser"] --> B["index.php / router.php<br/>single front controller"]
    B --> C["bootstrap/app.php<br/>composition root, route table"]
    C --> D["Shared HTTP and security boundary<br/>security headers, unsafe-request policy,<br/>session, CSRF, rate limiter"]
    D --> E{"Route type"}
    E -->|"Public page"| F["Site / Contact controller"]
    E -->|"/dhl/pickupsheet/..."| G["RecordsSession + RecordsAccess<br/>who is signed in, which role"]
    G -->|"no session"| H["Redirect to /dhl/pickupsheet/login"]
    G -->|"role lacks permission"| I["403, logged to security events"]
    G -->|"permitted"| J["Module controller<br/>Pickupsheet, CRM, Backup"]
    F --> K["Application service<br/>validation, business rules"]
    J --> K
    K --> L["Repository contract (Domain)"]
    L --> M{"Storage available?"}
    M -->|"yes"| N["MySQL adapter"]
    M -->|"local/demo"| O["Demo adapter (session data)"]
    M -->|"no"| P["Unavailable adapter<br/>writes fail closed"]
    N --> Q["Server-rendered view or JSON / XLSX response"]
    O --> Q
    P --> Q
```

1. Every request enters through `index.php` (Apache/LiteSpeed) or `router.php` (PHP built-in server).
2. `bootstrap/app.php` wires the dependencies and registers routes. There is no framework and no service container.
3. The shared boundary applies security headers, rejects unsafe cross-site requests, starts the hardened session, and gives controllers CSRF and rate-limit services.
4. Pickupsheet routes resolve the signed-in principal and check one permission per action (see [roles and permissions](technical-requirements.md#roles-and-permissions)).
5. Controllers call an application service. Services own validation and recalculation, and they never trust totals, times, agent names, or checker names sent by the browser.
6. Services talk to a repository interface. If MySQL is not configured or unreachable, an *Unavailable* adapter is used, so protected writes fail closed instead of half-saving.
7. Responses are server-rendered HTML. Pagination, search, and modals are enhanced with `public/assets/app.js`, and every page still works without JavaScript.

## 2. Sign-in and access

```mermaid
flowchart TD
    S["Open /dhl/pickupsheet"] --> CF{"Cloudflare Access JWT present<br/>and Access configured?"}
    CF -->|"yes, valid"| R["Map Access groups to role"]
    CF -->|"no or ?local=1"| L["Login page<br/>shows enabled methods"]
    L -->|"Continue with JumpCloud"| J1["Start OIDC<br/>state + nonce + PKCE S256"]
    J1 --> J2["JumpCloud sign-in"]
    J2 --> J3["Callback: verify state, code exchange,<br/>ID token signature, issuer, audience, nonce"]
    J3 --> R
    L -->|"Username + password"| P1{"CSRF valid and under rate limits?<br/>10 per IP and 15 per account / 15 min"}
    P1 -->|"no"| X["Refuse, log event"]
    P1 -->|"yes"| P2{"Password hash matches<br/>an active account?"}
    P2 -->|"no"| X
    P2 -->|"yes"| M1{"Local MFA enabled?"}
    M1 -->|"no"| R
    M1 -->|"yes"| M2{"Authenticator enrolled?"}
    M2 -->|"no"| M3["Enrol: QR secret, confirm a code,<br/>show one-time recovery codes"]
    M2 -->|"yes"| M4["Enter RFC 6238 code<br/>or a recovery code"]
    M3 --> R
    M4 -->|"valid, not replayed"| R
    M4 -->|"invalid"| X
    R --> SE["Start session<br/>new session ID, 8 h absolute, 1 h idle,<br/>ID renewed every 15 min"]
    SE --> A["Record session activity, log sign-in"]
    A --> W["Workspace menu filtered by role"]
```

- **Methods.** Local login and JumpCloud can each be switched on or off by an administrator (`pickup_auth_settings`). Cloudflare Access is optional: when the Pickupsheet path sits behind Access, a verified Access session skips the login page.
- **Roles.** JumpCloud and Cloudflare Access users get their role from group membership (`JUMPCLOUD_RBAC_*_GROUP`). Local users have a stored role. A user in no mapped group is refused.
- **MFA.** Secrets are stored encrypted with `PICKUPSHEET_MFA_ENCRYPTION_KEY`. Recovery codes are stored hashed and work once. A code's time step cannot be reused. MFA attempts are limited to 10 per 15 minutes.
- **Session end.** Sign-out, 1 hour idle, or 8 hours after sign-in, whichever comes first. Every sign-in, refusal, and sign-out is a security event.

## 3. Pickup sheet lifecycle

### 3.1 States

```mermaid
stateDiagram-v2
    [*] --> Open: Submit sheet
    Open --> Open: Admin edits (audited)
    Open --> Paid: Mark paid (receipt + amount + captcha)
    Paid --> Paid: Admin corrects receipt number (audited)
    Open --> Deleted: Admin deletes
    Paid --> Deleted: Admin deletes
    Deleted --> [*]
    note right of Paid: Paid cannot be reversed
    note right of Deleted: Soft delete, kept for audit, hidden from lists, reports and CRM totals
```

### 3.2 Submitting a sheet

```mermaid
flowchart TD
    A["Open new sheet form"] --> B{"Collection agent assigned?"}
    B -->|"no"| B1["Save disabled, admin prompted<br/>to assign the agent"]
    B -->|"yes"| C["Enter 1-50 shipment rows<br/>consignor autocomplete from sheets + CRM"]
    C --> D["Submit with privacy consent"]
    D --> E{"CSRF valid, under 30 / hour?"}
    E -->|"no"| X["Refuse"]
    E -->|"yes"| F["Server stamps: agent from settings,<br/>checker from signed-in account,<br/>collection time = server time, date = today<br/>unless admin"]
    F --> G["Normalise: uppercase names, collapse spaces,<br/>uppercase destination, strip amount separators"]
    G --> H{"Every row valid?"}
    H -->|"no"| X2["Return form with the error and values"]
    H -->|"yes"| I{"AWB unique on sheet and not used<br/>on another active sheet within<br/>±AWB_REUSE_DAYS of the date?"}
    I -->|"no"| X3["Refuse, name the conflicting sheet,<br/>log pickupsheet.awb_reuse"]
    I -->|"yes"| J["Recalculate total cash, generate reference<br/>PS-YYYYMMDD-32 hex"]
    J --> K["Save sheet + shipments in one transaction"]
    K --> L["Show reference, print / export links"]
```

### 3.3 Marking paid and later changes

1. An operator or administrator clicks **Mark paid** on Submitted sheets. The modal loads a single-use security question from the server.
2. They enter the receipt number (6–64 characters, at least 6 digits) and the amount on the receipt.
3. The server refuses the payment unless the amount equals the sheet's recalculated total. It then sets `status = paid`, `paid_at`, `paid_by`, and the receipt number, and writes a lifecycle audit row with before and after snapshots.
4. Paid status cannot be undone. Only an administrator can change the receipt number afterwards. The old number stays in the audit row.
5. **Edit** (administrators only) rewrites the shipment rows. The sheet keeps its agent and reference, the checker becomes the editing administrator, and before/after snapshots go to `pickup_sheet_edit_audit`. AWB reuse is checked again, excluding the sheet itself.
6. **Delete** (administrators only) sets `deleted_at` and `deleted_by`. Rows are kept for audit, but the sheet disappears from lists, dashboards, reports, and CRM totals.
7. **Print / PDF** opens an A4 view, with a PAID watermark once paid. **Export** produces a native XLSX file. Both need their own permission.

## 4. CRM and loyalty

```mermaid
flowchart TD
    A["Shipments saved on sheets"] --> B["CRM page opens"]
    B --> C["Sync: only shipment rows newer than<br/>pickup_crm_sync_state.last_shipment_id"]
    C --> D{"Consignor matches a profile name<br/>or a merged-away alias?<br/>case, accent and space-insensitive"}
    D -->|"profile"| E["Count shipment toward that profile"]
    D -->|"alias"| F["Rename consignor to the kept profile,<br/>audit the change"]
    D -->|"none"| G["Create profile, source = shipment"]
    E --> H["Profile metrics: shipments, cash,<br/>first/last shipment, points = floor(kg × 10)"]
    F --> H
    G --> H
    H --> I["Directory: search name, contact, email,<br/>city, alias, ID, phone digits"]
    H --> J["Profile: details, activity, follow-ups,<br/>shipment history, points history"]
    K["Submitted sheets: click consignor name"] --> L["/customers/open?name=&from="]
    L --> D2{"Profile found?"}
    D2 -->|"yes"| J
    D2 -->|"no, after a sync"| I
    J --> M["Edit details (crm_update)<br/>names uppercase, letters, digits, spaces, hyphen"]
    J --> N["Admin: adjust points, merge duplicate,<br/>delete for erasure"]
    N --> O["Merge: move shipments, rewards, aliases;<br/>store snapshot for undo"]
    O --> P["Undo or Ignore from Recent merges"]
    J --> Q["Back link returns to where<br/>the profile was opened"]
```

- **One customer, one profile.** Names are unique under `utf8mb4_unicode_ci`. The add form and save both refuse a name already used by a profile or an alias. Duplicate review suggests near-identical names or a shared email or phone, but never names that differ by a number (branches).
- **Renames** update the consignor on every past shipment for that customer, and each change is audited per sheet.
- **Points** are earned at 10 per kilogram shipped on non-deleted sheets, plus bonuses. Redemptions cannot go below the visible balance. Tiers by lifetime earned points: Bronze under 100, Silver 100+, Gold 250+, Platinum 500+.
- **Profile origin.** Links into a profile pass `from=` (submitted sheets, dashboard, or directory, with their page and filters). The profile remembers it for that customer in the session so the back link survives saves. Only same-site Pickupsheet paths are accepted.

## 5. Public contact enquiry

```mermaid
flowchart TD
    A["Visitor opens /contact"] --> B["Form with a server-issued captcha"]
    B --> C["Submit"]
    C --> D{"CSRF valid and under<br/>10 per hour?"}
    D -->|"no"| X["Refuse"]
    D -->|"yes"| E{"Honeypot field empty?"}
    E -->|"no"| X2["Silently drop, log contact.honeypot"]
    E -->|"yes"| F{"Captcha answer correct?"}
    F -->|"no"| X3["Return form, log contact.captcha"]
    F -->|"yes"| G{"Name, email, service, 20-2,000<br/>character message, consent?"}
    G -->|"no"| X4["Return form with the error"]
    G -->|"yes"| H["Store in inquiries with consent time<br/>and notice version"]
    H --> I["Email CONTACT_EMAIL from CONTACT_FROM_EMAIL"]
    I --> J["Thank-you message"]
```

## 6. User and sign-in administration

```mermaid
flowchart TD
    A["Admin opens Manage users and RBAC"] --> B{"Action"}
    B -->|"Add / edit / deactivate / delete"| C["Local viewer or operator account<br/>username, names, role, password of 12+ characters"]
    C --> D{"CSRF valid, under 30 per hour,<br/>username free and not reserved?"}
    D -->|"yes"| E["Save, log the change"]
    D -->|"no"| X["Refuse with the reason"]
    B -->|"Reset a user's MFA"| F["Confirm with own password,<br/>own authenticator code, and a checkbox"]
    F --> G["Remove enrolment. User enrols again at next sign-in"]
    B -->|"Sign-in methods"| H["Switch local and JumpCloud login on or off"]
    B -->|"Collection agent"| I["Set the name stamped on new sheets"]
    B -->|"Admin password"| J["Change the password of a server-defined admin"]
```

- Administrator accounts are defined in the server environment (`PICKUPSHEET_RBAC_USERS`, or the legacy single-admin variables), not created in the app. Admins can manage only lower-tier local accounts.
- JumpCloud and Cloudflare Access users are managed in JumpCloud. Their role follows group membership at each sign-in.

## 7. Backup and restore

```mermaid
flowchart TD
    A["Admin opens Backup"] --> B{"Action"}
    B -->|"Download"| C["Enter a 16-200 character passphrase twice"]
    C --> D["Export every application table in one<br/>read transaction, up to 250,000 rows each"]
    D --> E["Encrypt: PBKDF2-SHA256 210,000 iterations<br/>then AES-256-GCM"]
    E --> F{"12 MiB or smaller?"}
    F -->|"yes"| G["Download the encrypted file, log the event"]
    F -->|"no"| X["Refuse"]
    B -->|"Restore"| H["Choose file, enter passphrase,<br/>type RESTORE"]
    H --> I{"Format, version, passphrase,<br/>and required tables valid?"}
    I -->|"no"| X2["Refuse: file or passphrase invalid"]
    I -->|"yes"| J["One transaction: clear tables,<br/>insert parents before children"]
    J --> K["Log the restore, show row counts"]
```
