# Accounting (Comptabilité)

What to declare to l'URSSAF, when, and the orders as a CSV file for the accountant.
A micro-entrepreneur selling goods declares, per month or per quarter, the **turnover cashed** in that period
(line « Ventes de marchandises (BIC) »), even when it is 0 €.

| Concept | Meaning |
|---|---|
| Periodicity | `monthly` (default) or `quarterly`, chosen per workspace (`Workspace::declarationPeriodicity`) |
| Period | A month (`2026-09`) or a quarter (`2026-T3`) in Europe/Paris time |
| Turnover of a period | Σ totals of the orders placed in it (after discounts: what customers paid) |
| Declaration | A period marked « déclarée », with the turnover declared and the moment (`UrssafDeclaration`) |

Model: `src/Domain/Accounting/`; overview `src/Application/Accounting/GetUrssafOverview/`.

## Rules

| # | Rule | Where | Tests |
|---|---|---|---|
| A1 | A period is declared by the **last day of the following month**: monthly → end of next month; quarterly → 30 April, 31 July, 31 October, 31 January | `DeclarationPeriod::deadline()` | `DeclarationPeriodTest` |
| A2 | Turnover of a period = Σ order totals whose date (Paris) falls in it; the estimated contribution is URSSAF 12.8 % of it (same rate as the event report) | `PeriodTurnover`, `UrssafContribution` | `AccountingUseCasesTest` |
| A3 | Status: « À venir » (not started), « En cours » (today inside), « À déclarer » (over, deadline not passed), « En retard » (deadline passed), « Déclarée », « Montant modifié » (declared, but its orders changed since), « Avant l'activité » (ends before the first order) | `DeclarationStatus::of()` | `DeclarationStatusTest`, `AccountingUseCasesTest` |
| A4 | Only a finished period can be marked declared; marking it again replaces the snapshot; the mark can be withdrawn (it does not change anything on urssaf.fr) | `UrssafDeclaration::record()`, `declare()`, `DeclarePeriodHandler`, `WithdrawDeclarationHandler` | `DeclarationPeriodTest`, `AccountingUseCasesTest` |
| A5 | Pending periods (to declare, late, changed) are listed from the period of the first order up to the last finished one, oldest first | `DeclarationStatus::isPending()`, `GetUrssafOverviewHandler` | `DeclarationStatusTest`, `AccountingUseCasesTest` |
| A6 | The CSV export lists every order between two days (inclusive, Paris time), oldest first, one line per order: reference, date, time, source (Saisie or the service's name), event, payment, item count, detail, subtotal, discounts, shipping, total cashed, cost of goods, supplies, channel costs, margin (total − all three). UTF-8 with BOM, `;` separator, decimal comma (opens as is in a French Excel) | `ExportOrdersHandler` | `AccountingUseCasesTest`, `accounting.spec.js` |
| A7 | Supplies and channel costs (fees, postage) never lower the declared turnover: URSSAF is on what was cashed | `PeriodTurnover` | `AccountingUseCasesTest` |
| A8 | A period's turnover is **split by payment method**: Carte, Espèces, Mixte (merged orders paid both ways, O14) and « Paiement non précisé » (manual orders, which carry no payment method); only the methods used are shown | `SalesLedger::turnoverByMonthAndPayment()`, `PeriodTurnover` | `SalesLedgerTest`, `AccountingUseCasesTest` |

## Use cases & API

| Use case | Endpoint |
|---|---|
| `GetUrssafOverview` | `GET /api/accounting/urssaf?year=` → periods of the year (each with `payments: {card\|cash\|mixed\|none: cents}`), pending periods, year totals |
| `ChoosePeriodicity` | `PUT /api/accounting/urssaf/periodicity` `{periodicity: monthly\|quarterly}` |
| `DeclarePeriod` | `PUT` / `DELETE /api/accounting/urssaf/{2026-09\|2026-T3}/declaration` |
| `ExportOrders` | `GET /api/accounting/orders.csv?from=YYYY-MM-DD&to=YYYY-MM-DD` (attachment `commandes-…-au-….csv`) |

## UI

`/accounting` (sidebar group « Bureau »): periodicity switch (« Chaque mois » / « Chaque trimestre ») and year in the header; the selected period as a declaration slip (the URSSAF line and the amount to declare, its split by payment method, order count, estimated contributions, deadline, « Marquer comme déclarée » / « Annuler la déclaration »), the other pending periods as links, then the year ledger (one cell per month or quarter with amount and status, click to open it) and « Exporter les commandes » (Mois dernier, Trimestre dernier, Cette année, Période personnalisée → « Télécharger le CSV »).
