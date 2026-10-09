# Design brief

How the T&Tech website and the Pickupsheet workspace should look and behave, and the rules to follow when adding or changing a screen. Design tokens live at the top of `public/assets/styles.css`. Use them rather than new values.

Related: [Product requirements](../product/product-requirements.md) · [App flows](../architecture/flows.md)

## Two surfaces, one codebase

| | Public website | Pickupsheet workspace |
|---|---|---|
| Audience | Prospective clients and partners | Counter staff, supervisors, managers |
| Job | Build trust, explain services, collect enquiries | Record cash shipments fast and correctly, and find them again |
| Tone | Confident, corporate, spacious | Plain, operational, dense but calm |
| Palette | Near-black `--navy #080808`, white, red accent `--copper #d40511` | DHL yellow and red on an off-white ground (60-30-10, below) |
| Signature | Serif italic emphasis in headings (Georgia) | DHL yellow header and table headers |

## Principles

1. **Speed at the counter.** Entering a sheet must work one-handed on a phone. Use large targets, autocomplete, and server-filled fields (agent, checker, time) so staff type as little as possible.
2. **Show the state of money clearly.** Open and Closed (paid) must be readable at a glance. Paid is final, and the UI says so before confirming.
3. **Prevent rather than correct.** Validate as staff type (uppercase name filtering, number formats). Then refuse on the server with a message that says exactly what to fix.
4. **One way to do each thing.** The same header, menu, table, modal, and pagination patterns appear on every workspace page.
5. **Works without JavaScript.** Scripts enhance links and forms, and never replace them.

## Colour

Pickupsheet uses the 60-30-10 rule:

| Share | Token | Value | Use |
|---|---|---|---|
| 60% | `--pickup-ground`, `--pickup-surface` | `#faf8f2`, `#ffffff` | Page background, cards |
| 30% | `--pickup-structure` / `--pickup-structure-ink` | DHL yellow `#ffcc00` / `#241b00` | Sticky header, table header rows, active tab, login side panel |
| 10% | `--pickup-accent` | DHL red `#d40511` | Primary buttons, badges, alerts, enabled toggles, highlight lines |

- **Text.** Use browns: `#241b00` (headings), `#493800` (body), `#655000` (labels and captions). Card subheadings are brown, which keeps red for actions and alerts.
- **Status colours.**

  | Status | Colours |
  |---|---|
  | Success, paid, active | Green `#0b633a` on `#e6f7ed` |
  | Needs attention, error | Red `#a6000a` on `#fff0f1` |
  | Warning | Amber `#8a5a00` |
  | Closed, inactive | Gray `#262626`–`#666` on `#ededed` |
  | New customer | Blue `#174a96` on `#e8f0fc` |

- **Links in tables.** AWB tracking links are bold red with no underline. Consignor links are bold, inherit the text colour, and have a soft underline that turns red on hover.
- **Contrast.** Text and controls must meet WCAG 2.1 AA contrast. Never put red text on yellow.

## Typography

- **Font stack.** `Inter, ui-sans-serif, system-ui, -apple-system, "Segoe UI", sans-serif`. Inter is not loaded from a font service, so most devices show the system font. The layout must look right with either (see the implementation plan).
- **Website headings.** These may use a Georgia italic `em` for one emphasised word. Workspace headings do not.
- **Workspace scale.** Base 14–16px. Table text about 0.74rem. Table headers 0.61rem, uppercase, with 0.06em letter spacing. Badges about 0.58rem, bold.
- **Writing.** Sentence case for labels, buttons, and messages ("Mark paid", "Back to submitted sheets"). Names and codes the system stores in uppercase (consignors, customers, agents, checkers, destinations, receipt numbers) are shown as stored.

## Layout and breakpoints

- **Content width.** Up to `--container: 1240px`, centred, with side padding.
- **Breakpoints.**

  | Width | Change |
  |---|---|
  | 900px and below | Workspace header becomes a slim bar with the page name and a Menu button. Links open as a full-width list with 48px rows |
  | 820px, 700px, 620px | Grids collapse. Dashboard KPI and market cards use two compact columns |
  | 580px, 480px, 430px | Tables stack into labelled rows (`data-label`). The footer may wrap to two lines (reserved height 44px → 64px) |
  | 360px | Minimum supported width. Nothing may be wider than the screen |

- **Wide content.** Charts and long tables scroll sideways inside their own card, never the page. Stacked grids use `minmax(0, 1fr)` so wide content cannot stretch a card.
- **Footer.** Pinned to the bottom on every workspace page, with "© {year} T&Tech Consulting Group. All rights reserved." and a Privacy link. Printed sheets and reports have no footer.

## Components

| Component | Rules |
|---|---|
| Workspace header | One shared partial (`_workspace-header.php`), with the menu filtered by role and the current page marked `aria-current="page"`. Ends with Sign out |
| Buttons | Page actions are filled red. In modals, Cancel is outlined brown, the confirm button is filled green, and a destructive confirm is filled red. At least 42–48px tall. Disabled is 48% opacity and is never the only signal |
| Cards | White on the off-white ground. A heading row has a brown eyebrow label, a title, and an optional right-aligned summary |
| Tables | Yellow header row, 1px brown hairlines, and a total row in the footer. On phones each cell shows its `data-label` |
| Status badges | Pill shape (`border-radius: 999px`) with a 1px border in the status colour. Open / Closed, customer status, and New |
| Modals | `<dialog>` with **square corners**. Title shows the full sheet reference. A form view, then a result view with a tick or cross, a title, and a message. Use the in-page confirmation dialog, never `confirm()` |
| Forms | Labels above fields, help text below, and live status lines (`aria-live="polite"`). A green tick appears in the field when it is valid. Name fields (`data-name-field`) uppercase and filter as you type |
| Pagination | First, previous, page x of y, next, and last, plus page size 10/25/50. Works as plain links and is enhanced with AJAX that updates the URL |
| Back links | Arrow and destination name ("← Back to submitted sheets"). The destination is always where the user came from |
| Print views | A4 with the brand header. Sheets show a large diagonal semi-transparent green PAID watermark once paid. Reports use server-rendered SVG charts with a table under each |

## Accessibility

- Semantic HTML: real `<button>`, `<a>`, `<table>`, `<dialog>`, and labels tied to inputs.
- Visible focus rings (`:focus-visible`, a 3px red outline at 24% opacity) on every interactive element.
- `prefers-reduced-motion` turns off transitions and hover movement.
- Status is never shown by colour alone. Badges carry text, and the AWB "tracking expired" state carries a label.
- Charts have titles, legends, and a data table, and never use two y-axes.
- Error messages name the field and the fix ("Shipment 3: the consignor name may contain only letters, numbers, spaces, and hyphens.").

## Do and don't

| Do | Don't |
|---|---|
| Use the existing tokens and shared partials | Introduce new colours, radii, or a second header |
| Keep red for actions and alerts | Use red for decoration or subheadings |
| Bump `styles.css?v=` / `app.js?v=` after changing assets | Ship CSS or JS changes without a cache version bump |
| Test at 360px and 1240px, with and without JavaScript | Let a table or chart push the page wider than the screen |
| Write sentence-case, specific labels | Use vague labels like "Submit" or "OK" for money actions |
