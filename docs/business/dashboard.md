# Dashboard (Carnet de bord)

Results **per month and per year**, all events together. Home page of the app (`/` → `/dashboard`).

Model: `src/Domain/Reporting/SalesFigures.php` (the formula, shared with the [event report](event-report.md)),
`MonthlyResults.php` (bucketing); use case `GetDashboard` → `GET /api/dashboard[?year=YYYY]`; UI `DashboardPage.vue`.

## Rules

| # | Rule | Where | Tests |
|---|---|---|---|
| B1 | Same formula as an event: turnover = Σ order totals (Etsy orders included, with their shipping); URSSAF = 12.8 % × turnover; result = turnover − cost of goods − expenses − URSSAF + donations (counted in the month the event starts, like its expenses, B3) | `SalesFigures::of()` | `MonthlyResultsTest`, `EventResultTest` |
| B2 | An order counts in the **month of its date** (Europe/Paris) | `MonthlyResults::of()` | `MonthlyResultsTest` (UTC/Paris boundary) |
| B3 | An event's expenses (its shares of shared expenses, [events.md](events.md) E8) count in the **month the event starts** (even when it spans two months) | `MonthlyResults::of()` | `MonthlyResultsTest` |
| B4 | A year is the **sum of its months** (URSSAF rounded per month, then summed) | `MonthlyResults::year()`, `SalesFigures::add()` | `MonthlyResultsTest` |
| B5 | Selectable years = years with orders or expenses, plus the current year | `GetDashboardHandler` | `GetDashboardTest` |
| B6 | The year's events = events **starting** in the year (as for expenses, B3), ranked by result, best first | `Event::startsIn()`, `GetDashboardHandler` | `GetDashboardTest` |
| B7 | Best sellers = order lines of the year's orders (B2) summed per product, all variants together, after their share of the discounts (O20); top 5 products, and every product type (untyped products together, « Sans type »), by sales | `ProductSalesLedger::within()`, `SalesByProduct`, `GetDashboardHandler` | `SalesByProductTest`, `GetDashboardTest` |
| B8 | Supplies and channel costs (charges + postage) of the orders count like their cost of goods: in the month of the order (B2); supplies consumed at an event's inventory count in the month the event starts (B3); all shown together as « Frais de vente » in the tables | `SalesFigures`, `ResultsTable.vue` | `ChannelCostTest` |

## Display

- A warning when products were never bought (their cost counts as 0 €, so the result is overstated), linking to them on `/products`; another when products are low or negative in stock.
- Year selector; the year's result as a receipt: chiffre d'affaires − coût d'achat − dépenses − URSSAF = résultat, with the result as a share of the turnover and the number of orders and events.
- Bar chart of the monthly result from a zero baseline (green gain, red loss, value on each bar, hover/focus for figures).
- The year's events as bars of their result (B6), and the best sellers (B7).
- Table month by month with the year total (accessible view of the chart); months without orders nor expenses are hidden until « Afficher les 12 mois ». A table comparing years appears once there are at least two.
