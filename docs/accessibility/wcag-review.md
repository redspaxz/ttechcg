# WCAG review

A review of the T&Tech website and Pickupsheet against WCAG 2.2 Level AA, which includes every Level A criterion. The focus is on keyboard and screen-reader users. It covers the code on `main` as of 2026-10-12.

Related: [Design brief](../design/design-brief.md#accessibility) · [Product requirements](../product/product-requirements.md#non-functional-requirements) · [OWASP review](../security/owasp-review.md)

## How it was checked

- **Automated rules.** axe-core 4.10 in Chrome, with the WCAG 2.0, 2.1, and 2.2 A and AA rule sets. It ran on 7 public pages, the login page, 9 workspace pages, a customer profile, the edit-sheet form, and the payment dialog while open. The workspace was seeded with 11 sheets, so tables, pagination, and dialogs were tested with data.
- **Keyboard.** A scripted Tab walk through every page. For each stop it checked whether the element was visible, whether a focus indicator showed, whether anything covered it, and whether focus got trapped. Flows tested:
  - skip link
  - mobile menu, including Escape
  - consent banner
  - payment and privacy dialogs, including Escape and where focus returns
  - dashboard tabs with the arrow keys
  - AJAX pagination
  - adding a shipment row
- **Screen-reader structure.**
  - headings: one `h1` per page, no skipped levels
  - one `main` landmark, named navigation regions and dialogs
  - `lang`, and a unique title on every page
  - labels tied to inputs
  - live announcements
- **Contrast** measured against the WCAG formula, including focus rings against every background they sit on.
- **Reflow and spacing.** Every page was checked at 320px wide, which equals 400% zoom. Pages were also checked at 1280px and 390px with the 1.4.12 text-spacing overrides applied.

## Findings and fixes

| # | Criterion | Finding | Fix |
|---|---|---|---|
| 1 | 2.4.1, 2.4.7 | The dark public theme turned the skip link into dark text on a dark box, so it was invisible when focused, on every page | Fixed colours: dark text on white with a red border |
| 2 | 1.4.11, 2.4.7 | Most workspace focus rings were red at 12–35% opacity, about 1.5:1, and some controls used `outline: none` with only a faint background change, as on Show password | One solid 3px ring enforced last in the stylesheet: `#241b00` in the workspace (11:1 or better, including on DHL yellow) and `#ff5a63` on the public site (6:1) |
| 3 | 1.4.3 | DHL red small text on the dark public site measured 3.3–3.6:1. This covered eyebrows, kickers, numbers, office labels, and email and consent links | These use the lighter red `--copper-light`, 6.0–6.5:1 |
| 4 | 1.4.3 | An unavailable sign-in method faded its whole card to 72% opacity, making its text 3.2–3.4:1 | Full opacity with `#595959` text, 5.9:1 |
| 5 | 1.3.1, 4.1.2 | Shipment-row labels on the new and edit sheet forms were not tied to their inputs. Screen readers read most fields with no name or only the placeholder | `for`/`id` on every field (7 on the new form, 8 on edit). Rows added or renumbered by script keep matching ids |
| 6 | 2.4.11 | Tabbing down the new-sheet form scrolled shipment fields under the fixed footer | `scroll-padding` keeps focused fields clear of the sticky header and fixed footer, on desktop and phone |
| 7 | 2.4.3, 4.1.3 | AJAX pagination replaced the table, including the link that had focus. Focus dropped to the page top and the new page was not announced | Focus stays on the equivalent control, or the page status if that control is gone. A polite live region reads "Page 2 of 2, 11 records" |
| 8 | 2.4.3 | The consent banner pushed focus onto "Accept analytics" on every visit, and lost focus after a choice | Focus goes to the banner, which is read in full with no answer pre-selected. Afterwards it returns to Cookie settings or the top of the page |
| 9 | 3.3.1, 4.1.3 | Errors after a failed submit sat in a `role="alert"` box that screen readers often skip on page load | Focus moves to the error message |
| 10 | 2.5.3 | "Let's talk" was named "Start a conversation", and the logo was named "T and Tech…" | Names now contain the visible text |
| 11 | 2.4.7 | Content still fading in could be focused at 0% opacity | Focused content shows at once |
| 12 | Best practice | The print views' toolbar sat outside any landmark | It is a labelled `nav` |

## Results after the fixes

| Check | Result |
|---|---|
| axe WCAG 2.0–2.2 A/AA rules, 20 views including the open payment dialog | No violations |
| Keyboard traps | None. A date field takes 4 Tab presses (day, month, year, picker), which is normal |
| Skip link first and visible, menus and dialogs by keyboard, Escape, focus return, arrow-key tabs | Pass |
| Focus hidden by the header or footer, at 1280px and 390px | None |
| Headings, `main`, named landmarks and dialogs, `lang`, unique titles | Pass on all 17 pages |
| Reflow at 320px | No sideways page scrolling on any page. Wide tables scroll inside their own card, which WCAG allows |
| Text spacing (1.4.12) at 1280px and 390px | No clipped visible text |
| Autofill purposes (1.3.5) | Every personal-data field has the right `autocomplete` token |
| Target size (2.5.8) | Pass. Header links are 18px tall but spaced well apart, which meets the spacing exception |

Already sound before this review:
- `<dialog>` modals
- the ARIA combobox for consignor autocomplete
- the mobile menu, which uses `inert` and closes with Escape
- `prefers-reduced-motion` support
- error messages that name the field and the fix

## Not covered and next steps

Automated and scripted checks find most problems, but not all. Before claiming conformance:

1. **Test with real screen readers:**
   - NVDA with Chrome on Windows: sign in, submit a sheet, mark it paid, and page through Submitted sheets.
   - VoiceOver on an iPhone: the same tasks on a phone.
   - TalkBack on Android: the public contact form.
2. **Test with Windows High Contrast**, under Settings, Accessibility, Contrast themes. Check that focus rings, toggles, and status badges stay visible.
3. **Ask a keyboard-only or screen-reader user** to try the counter workflow, and record what they found.
4. **Charts.** Dashboard and report charts have a data table, but nobody has checked by ear that a screen reader reads those tables usefully.
5. **Publish an accessibility statement** on the site once steps 1–3 are done.

## Re-checking

- Tab through any changed screen. Check that focus is always visible, never covered, follows reading order, and that Escape closes whatever opened.
- Run the axe DevTools browser extension (free) on the changed page, with the WCAG 2.2 AA tags.
- `php tests/run.php` includes regression checks for each fix above.
