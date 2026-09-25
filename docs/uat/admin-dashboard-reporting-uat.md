# UAT: Administrator dashboard, market analysis and reporting

| Item | Detail |
|---|---|
| Release | Admin market analysis, dashboard folder tabs, printable performance reports |
| Commits | `2971ce1`, `30076d3`, `669831f` |
| Area | Pickupsheet → Activity dashboard (`/dhl/pickupsheet/dashboard`) |
| Testers | Operations administrator and one operator account |
| Status | Ready for UAT |

## 1. Purpose

Confirm that administrators can review market performance, move between dashboard sections using tabs, and generate printable performance reports. Also confirm that non-administrators cannot open the dashboard or reports.

## 2. Before you start

- Use the UAT environment, not production.
- Have two accounts ready: one **admin** and one **operator**.
- Make sure the UAT database has pickup sheets from at least the last 6 months. Include a mix of paid and unpaid sheets, several destinations, and at least one sender with sheets on **different days**.
- Use a current desktop browser (Chrome, Edge or Firefox), and have a phone or a narrow browser window for the mobile checks.
- If you tested an earlier build, hard-refresh the page (Ctrl+F5) once so the browser loads the new styles.

Record each result as **Pass**, **Fail** or **Blocked**. For a failure, note what you saw and attach a screenshot.

## 3. Test cases

### A. Dashboard tabs

| ID | Steps | Expected result | Result | Notes |
|---|---|---|---|---|
| A1 | Sign in as admin and open the Activity dashboard. | The header, admin links and the four KPI cards appear at the top. Below them is a row of eight tabs: Market analysis, Cash activity, Top senders, Loyalty, User activity, Audit log, Recent sheets, Reports. **Market analysis** is open. | | |
| A2 | Click each tab in turn. | Only the chosen section shows. The page does not reload, and the page stays short, with no long scroll through every section. | | |
| A3 | Open **Audit log**, then press F5. | The page reloads with **Audit log** still open. The address bar ends in `?tab=logs`. | | |
| A4 | Copy the address with the **Loyalty** tab open and paste it into a new browser tab. | The dashboard opens directly on **Loyalty**. | | |
| A5 | On **User activity**, go to page 2 of the table. Then press F5. | Page 2 loads without leaving the tab. After the reload, you are still on **User activity**, page 2. | | |
| A6 | Click a tab, then use the Left/Right arrow keys, Home and End. | Focus and the open section move between tabs. Home jumps to the first tab, End to the last. | | |
| A7 | Narrow the browser to phone width, or use a phone. | The tab row scrolls sideways. Nothing overflows the page edge, and the cards stack in one column. | | |

### B. Market analysis

| ID | Steps | Expected result | Result | Notes |
|---|---|---|---|---|
| B1 | Open **Market analysis**. | Four comparison cards show: Shipments, Cash recorded, Cargo weight, Active senders. Each card shows the latest 90 days, the previous 90 days and a change badge. | | |
| B2 | Compare the Shipments and Cash figures with the records list filtered to the same dates. | The figures match. Deleted sheets are not counted. | | |
| B3 | Check a metric that had no activity in the previous period. | The badge reads **New baseline** (amber), not a percentage. | | |
| B4 | Check the six efficiency figures: pieces, cash per shipment, cash per kg, payment conversion, repeat sender rate, top lane concentration. | All six show values. The small caption under each says which period it covers. | | |
| B5 | Review the 12-month trend. | Twelve months are listed, oldest first. Months with no activity show 0 and are not skipped. | | |
| B6 | Review **Leading shipment lanes**. | Up to eight destinations appear, ranked by shipments, each with its share %, cash and weight. | | |

### C. Performance reports

| ID | Steps | Expected result | Result | Notes |
|---|---|---|---|---|
| C1 | Open the **Reports** tab. | A form shows four period options (30, 90, 180, 365 days, with 90 selected) and six section checkboxes, all ticked. | | |
| C2 | Click **Generate report** with the default settings. | The report opens in a new tab as an A4 page, and the print dialog opens. It includes all six numbered sections, the reporting and comparison dates, **Prepared by** with your name, and the generation time. | | |
| C3 | Compare the report's KPI and market figures with the dashboard. | The figures match the dashboard for the same 90-day period. | | |
| C3a | Review the charts in each section. Hover over a bar or point. | Every section has a chart above its table, and chart values match the table. Charts with two colours have a legend. Hovering shows the exact value. Months with no activity show as zero, not as gaps. | | |
| C4 | Choose **30 days**, tick only **Destination mix** and **Period-over-period market performance**, then generate. | The report shows just those two sections, numbered 1 and 2, and the period reads "(30 days)". | | |
| C5 | Untick every section and generate. | The full report (all six sections) is generated. | | |
| C6 | Print to PDF using **Save as PDF**. | The PDF has no buttons or grey background. Tables are not cut mid-row, and table headings repeat on each new page. | | |
| C7 | On the report, click **Back to reports**. | The dashboard opens on the **Reports** tab. | | |
| C8 | Open **Audit log** after generating a report. | A **Records access** entry appears for your account with **Action: report**. | | |

### D. Access control

| ID | Steps | Expected result | Result | Notes |
|---|---|---|---|---|
| D1 | Sign in as the **operator** and open `/dhl/pickupsheet/dashboard`. | Access is refused ("You do not have permission…"). | | |
| D2 | As the operator, open `/dhl/pickupsheet/dashboard/report` directly. | Access is refused. No report data is shown. | | |
| D3 | Sign out and open the report address. | You are sent to the Pickupsheet sign-in page. | | |

## 4. Known limitations (not defects for this UAT)

- **Repeat sender rate** counts a sender with two or more parcels as "repeat", even if both were collected in the same pickup. It may overstate returning customers. A fix is planned.
- **Cash settlement** in reports always covers the last 3 months. **Trend, destinations, senders and repeat-sender rate** always cover 12 months, whatever period you choose. Each figure's label in the report says so.
- The "today" part of the current period is incomplete, so growth figures read slightly lower early in the day.
- All figures are internal operational records. They are not accounting revenue or external market data.

## 5. Sign-off

| Role | Name | Decision (Accept / Reject) | Date | Signature |
|---|---|---|---|---|
| Operations administrator | | | | |
| Business owner | | | | |
| Technical lead | | | | |
