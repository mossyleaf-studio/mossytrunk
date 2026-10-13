# Event report (Bilan d'un événement)

At the end of an event the user wants to know what was sold, what was spent and what is left.
The report is **computed on the fly** from the event and its orders — nothing is stored.

Model: `src/Domain/Reporting/SalesFigures.php` (formula shared with the [dashboard](dashboard.md)), `UrssafContribution.php`, `ProductSales.php`;
use case `GetEventReport` → `GET /api/events/{id}/report`; UI: report card on `/events/{id}` (`EventReport.vue`).

## Formula

```
gross sales        = Σ order subtotals (unit selling price snapshot × qty)
discounts          = Σ order discounts (rules, « Remise {service} »)
turnover (CA)      = gross sales − discounts                  = Σ order totals
cost of goods      = Σ line costs (units taken from stock)
supplies           = Σ order supply costs (O17)
supplies consumed  = Σ cost of supplies missing at the event's inventories (K17)
channel costs      = Σ order channel charges + postage (O18)
URSSAF             = 12.8 % × turnover   (rounded to the cent, half up)
donations          = Σ donations received at the event (events.md E9)
result (Résultat)  = turnover − cost of goods − supplies − supplies consumed − channel costs − expenses − URSSAF + donations
```

| # | Rule | Where | Tests |
|---|---|---|---|
| R1 | URSSAF contributions are **12.8 % of the turnover** (after discounts), micro-entrepreneur flat rate for selling goods. The rate lives in one constant | `UrssafContribution::RATE_BASIS_POINTS` | `EventFiguresTest`, `MoneyTest` |
| R2 | Cost of goods uses the cost of the units **taken from stock at the time of sale** (oldest lot first, see [stock.md](stock.md)). A cost of 0 means *unknown* (product never bought) and is flagged with a warning icon | `ProductSalesLedger::ofEvent()`, `OrderRecap` | `EventReportTest`, `OrderRecapTest` |
| R3 | Only the event's own orders count | `GetEventReportHandler` | `EventReportTest` |
| R4 | The result can be negative (loss) | `SalesFigures::of()` | `EventFiguresTest` |
| R5 | Supplies and channel costs of the event's orders lower the result; they do not change the turnover nor URSSAF (rows shown only when not zero) | `SalesFigures::of()` | `ChannelCostTest` |
| R6 | Supplies found missing at the event's inventories (flyers, giveaways) are consumed by the event: « Fournitures consommées (inventaire) » lowers its result, in the list of events and the dashboard too (month the event starts, like its expenses) | `ConsumedSupplies`, `GetEventReportHandler`, `MonthlyResults::of()` | `StockUseCasesTest` |
| R7 | Donations raise the result only: neither turnover nor URSSAF; shown as « + Dons reçus » after URSSAF when not zero, in the report and the dashboard | `SalesFigures::of()`, `useResultLines.js` | `EventFiguresTest`, `EventApiTest` |

## Display (visual grouping only — not domain concepts)

The report is a **receipt**: the result in large type (with its share of the turnover and the number of orders), next to the ledger
chiffre d'affaires − coût d'achat − dépenses − URSSAF (rate shown) = **Résultat**.

- **Chiffre d'affaires** unfolds (open when there are orders) into gross sales, discounts and the **orders recap**: an accordion
  grouped by **type → product → variants** (quantity and sales after their share of the discounts, O20, at each level — the products add up to the turnover without shipping; warning icon when a cost is unknown).
  Types and names are the products' current ones (untyped or deleted products under « Sans type »), see `GetEventReport/OrderRecap.php` (`OrderRecapTest`).
  The article list can be hidden with « Afficher le détail des articles » (remembered in the browser). Link to the orders list filtered on the event.
- Expenses are listed (and edited) in their own card below the receipt.
- An **upcoming** event without orders shows its **committed expenses** instead of a (negative) result.
