# Orders (Commandes)

An **order** is a sale made during an event, or an online sale imported from a connected service (then without event, see [imports.md](imports.md)).

| Field | Meaning |
|---|---|
| `reference` | Written with the workspace's order format ([references.md](references.md), `CMD-YYYYMMDD-XXXXXX` by default), imported orders included; unique within the workspace. The service's own reference (SumUp transaction code, `ETSY-<receipt id>`) is kept with the imported sale |
| `event` | The event the sale happened at (required, except for online imported orders) |
| `shipping` | Shipping charged to the customer by the service (Etsy), part of the total |
| `placedAt` | Date-time of the sale (stored with time zone, displayed in Europe/Paris) |
| `lines` | `(product ULID, variant)` tuple + quantity, with **snapshots** of product name, unit selling price and the **cost** of the units taken from stock |
| `appliedDiscounts` | Snapshot list of `{label, amount, ruleId}` (`ruleId` null for « Remise SumUp » and older orders) |
| `paymentMethod` | `card` or `cash`, given by the service on import (SumUp S13, Etsy card); none for manual orders |
| `source` | `manual` or the key of the service it was imported from (`sumup`, `etsy`) |
| `externalId` | The sale's id at the service; unique per source within the workspace |
| `refundedAt` | Date-time of the refund, null while the order is not refunded |

Model: `src/Domain/Order/Order.php`, `OrderLine.php`, `OrderedItem.php`.

## Rules

| # | Rule | Where | Tests |
|---|---|---|---|
| O1 | A manual order, or an order imported at the day's market, always belongs to an event, and its date must fall within the event's days; an online imported order has none | `Order::__construct()` (`Event::covers()`) | `OrderTest` |
| O2 | **The event is deduced from the date** (events never overlap). No event at that date → the order is refused with « Aucun événement le … Créez d'abord l'événement » | `PlaceOrderHandler` (`EventRepository::findCovering()`) | `OrderUseCasesTest` |
| O3 | At least one line; quantities ≥ 1 | `Order`, `OrderLine`, `OrderPricing::items()` | `OrderTest`, `OrderUseCasesTest` |
| O4 | Each line is a valid (product, variant) tuple: variant mandatory for products with variants, forbidden for unique products | `Product::sellable()` via `OrderPricing` | `ProductTest`, `OrderUseCasesTest` |
| O15 | A line is priced at the product's selling price **on the order's date and time** (the latest price-history entry not after it, P17/P18; its first price before the history starts), so a manual order entered after the event and its automatic discounts use the prices of that day. An imported sale is listed at that price too (or the linked channel's own price, C6), so a later price change never turns into a fake discount; products created by the import use their import price | `Product::priceAt()`, `Product::sellableOn()`, `OrderPricing::items()`, `ExternalItemResolver` | `ProductTest`, `OrderUseCasesTest`, `ImportSumUpSalesTest` |
| O5 | Identical tuples are merged into one line | `Order::addItem()` | `OrderTest` |
| O6 | Names/prices/costs are snapshots: editing a product never changes past orders. A line stores its **total cost**, computed when the units are taken from stock (FIFO, see [stock.md](stock.md)) | `OrderLine`, `StockKeeper` | `OrderTest`, `StockUseCasesTest` |
| O7 | Discount rules valid on the order's date are applied automatically to manual orders ([discounts](discounts.md)) | `OrderPricing::discounts()` | `OrderUseCasesTest` |
| O8 | Discounts never exceed the subtotal | `Order::applyDiscounts()` | `OrderTest` |
| O9 | `total = subtotal − discounts`; `costOfGoods = Σ line costs`; gross margin = total − cost of goods | `Order::total()`, `costOfGoods()`, `OrderView` | `OrderTest` |
| O10 | An event cannot be rescheduled if some of its orders would fall outside the new dates | `UpdateEventHandler` (`OrderRepository::countOutside()`) | `OrderUseCasesTest` |
| O11 | Every order has an **internal reference** written with the workspace's order format (R2 in [references.md](references.md)). An imported order keeps the sales it comes from (`ImportedSale`: service, external id unique within the workspace, the service's reference, payment method) so re-importing never duplicates them | `Order::imported()`, `ImportedSale` | [imports.md](imports.md) |
| O14 | Two orders of **one customer** (e.g. paid half in cash, half by card, recorded as two SumUp sales) can be **merged**: same event and same source only. The chosen order joins the one it is merged into: its lines (merged with a line of the same item at the same price), discounts, shipping and imported sales; the earliest date is kept, the payment becomes « Mixte » when methods differ, and the absorbed order is deleted. Stock is not touched (lines keep their costs). Both sales stay linked, so a later import skips them | `Order::absorb()`, `MergeOrdersHandler`, `PaymentMethod::combined()` | `OrderTest`, `ImportSumUpSalesTest`, `OrderApiTest` |
| O13 | An imported line may have **no product** (« Produit inconnu »: an amount typed on the card terminal, see [sumup-import.md](sumup-import.md) S10). It never merges with another line, takes no stock, costs nothing and matches no discount. It can later be **linked to a product and variant** (once): it keeps its unit price, takes the product's name, and its units leave the stock like a sale on the order's date (cost from the lots, K rules). Discounts are not recomputed | `SellableItem::unknown()`, `Order::identifyLine()`, `IdentifyOrderLineHandler` | `OrderTest`, `ImportSumUpSalesTest`, `OrderApiTest` |
| O15 | Orders ticked in the list (« Tout sélectionner » ticks the listed ones; Maj + clic on a box ticks or unticks every order between it and the box clicked before, in list order) are deleted together after confirmation; like a single deletion their stock withdrawals are erased ([stock](stock.md) K7). An order of another workspace aborts the whole deletion | `DeleteOrdersHandler`, `OrderDeletion` | `DeleteOrdersTest`, `OrderApiTest`, e2e `orders.spec.js` |
| O16 | **Deleting vs refunding**: deleting is for an order entered by mistake — it disappears with its stock withdrawal. Refunding is for a customer giving the items back — the order stays in the history marked « Remboursée » (once; it can then no longer be merged nor have a line linked to a product), its units come back to stock on the refund date, and it **no longer counts as a sale**: turnover, margins, URSSAF, accounting export, dashboard, event results, product sales and the stock sheet all leave it out | `Order::refund()`, `RefundOrderHandler`, `SalesLedger`, `ProductSalesLedger` | `OrderTest`, `StockUseCasesTest`, `OrderApiTest` |
| O17 | An order **uses supplies** (sleeve, envelope…) added by hand, only among the supplies **its channel offers** (an order without channel has none). Each addition withdraws the units from stock (FIFO, the event's inventory gaps first, like a sale) and stores their cost on the order; the same supply added again adds up. Removing a supply, or deleting the order, gives the units back. Supplies can be added to several orders at once only when they are all on **one channel**. A refunded order is locked. The order margin subtracts its supplies | `Order::useSupply()`, `returnSupply()`, `OrderSupply`, `AddOrderSuppliesHandler` (`OrdersOnSeveralChannels`), `StockKeeper::giveBack()` | `OrderSuppliesTest`, `OrderApiTest` |
| O18 | An order on a channel keeps a **snapshot of the channel's costs** computed on its total when it is placed or imported (fixed amount, or percentage rounded to the cent half up); merging recomputes them; changing the channel's costs does not touch past orders until « Recalculer les frais du canal » is run on them. Its **postage** (affranchissement) is typed on the order (≥ 0; a merge adds them up). Charges and postage lower the order margin | `Order::chargeChannelCosts()`, `stamp()`, `channelCosts()`, `RecomputeOrderChargesHandler`, `StampOrderHandler` | `ChannelCostTest`, `SalesChannelApiTest` |
| O19 | A line sold before its product had any known cost (cost 0, e.g. imported sales before the first supplier order) gets the **unit cost of the item's first purchase lot** × its quantity as soon as stock is received for that item (supplier order reception or correction, restock); « Compléter les coûts d'achat » on the orders page does it for every item already in stock. The orders list shows each order's **real profit** = total − cost of goods − supplies − channel costs (warning icon while a line's cost is unknown; refunded orders show none) | `Order::fillMissingCosts()`, `MissingCosts`, `FillMissingCostsHandler`, `Order::profit()` | `PurchasingUseCasesTest` |
| O20 | For per-product figures (event report, best sellers, product sales), an order's discounts are **shared between its lines in proportion to their price** (leftover cents to the largest remainders, so the shares add up exactly); a line's revenue = its price − its share. The order total is unchanged | `OrderLine::discount()`, `revenue()`, `CostAllocation::proportionally()` | `OrderTest`, `SalesLedgerTest` |
| O21 | An order **records its figures when it changes** — subtotal, discounts, total, cost of goods, supplies cost, channel costs, and each line's share of the discounts (O20) — at placement, import, merge, line identification, cost completion, supply changes, postage, channel cost recomputation and moved sales. Reading an order, a list or a report never recomputes them: lists and reports sum the recorded figures in the database | `Order::recordSales()`, `recordChannelCosts()`, `SalesLedger`, `ProductSalesLedger`, `OrderSummaries` | `OrderTest`, `SalesLedgerTest` |
| O22 | **Check after the event**: each order of an event can be ticked off as checked (and unticked) by the user, e.g. against the notebook kept at the stall; the first tick is kept (ticking again does not move it). The mark is only a note: it changes no figure and survives later edits of the order | `Order::check()`, `uncheck()`, `CheckOrderHandler`, `UncheckOrderHandler`, `OrdersToCheck` | `OrderTest`, `OrderCheckTest`, `OrderApiTest`, `order-check.spec.js` |

## Use cases & API

| Use case | Endpoint |
|---|---|
| `PlaceOrder` | `POST /api/orders` `{placedAt: "YYYY-MM-DDTHH:MM" (Paris time), lines: [{productId, variant, quantity}]}` → `{id, reference}` |
| `PreviewOrder` | `POST /api/orders/preview` (same body) → matching event (or null), discounts, totals — nothing saved |
| `ListOrders` | `GET /api/orders[?eventId=]` (most recent first) |
| `GetOrder` | `GET /api/orders/{id}` (lines, discounts, totals, cost of goods, supplies, margin, channel) |
| `DeleteOrder` | `DELETE /api/orders/{id}` |
| `RefundOrder` | `POST /api/orders/{id}/refund` → 204 (422 when already refunded) |
| `MergeOrders` | `POST /api/orders/{id}/merge` `{orderId}` → 204 (the given order joins `{id}`) |
| `IdentifyOrderLine` | `PUT /api/orders/{id}/lines/{lineId}/product` `{productId, variant?}` → 204 (422 when the line already has a product) |
| `DeleteOrders` | `POST /api/orders/deletion` `{orderIds[]}` → `{deleted}` |
| `AddOrderSupplies` | `POST /api/orders/supplies` `{orderIds[], supplyId, variant?, quantity}` → `{updated}` |
| `FillMissingCosts` | `POST /api/orders/missing-costs` → `{filled}` |
| `RecomputeOrderCharges` | `POST /api/orders/charges` `{orderIds[]}` → `{updated}` |
| `StampOrder` | `PUT /api/orders/{id}/postage` `{postage}` → 204 |
| `RemoveOrderSupply` | `DELETE /api/orders/{id}/supplies/{supplyLineId}` → 204 |
| `CheckOrder` / `UncheckOrder` | `PUT` / `DELETE /api/orders/{id}/check` → 204 |
| `ListOrdersToCheck` | `GET /api/events/{id}/orders-to-check` → the event's orders oldest first `{id, reference, placedAt, source, sourceLabel, paymentMethod, total, refunded, checked, lines: [{label, quantity, unidentified}]}` |

UI: `/orders` — order history (filter by event; a search box matches the order number or the service's references, the date — `dd/mm/yyyy`, `yyyy-mm-dd` or the written day — and the time — `15:30` or `15h30` —, every word having to match; filters kept in the URL), grouped by **day and event** with the day's total and number of orders; an order with lines without product is highlighted with a warning icon, and a « N commandes avec des articles sans produit » toggle lists only them; each order shows its time, articles, discounts, total, payment method (Carte / Espèces) and (secondary) its reference. Side by side, the new-order form: date, product → variant (only when needed) → quantity, live preview of the matching event, and a footer pinned at the bottom with discounts, total and the save button. On save: toast, list refresh without page reload, new row highlighted. `/orders/{id}` — detail with margin, the service's references, « Fusionner avec… » (orders of the same event and source, closest in time first), « Rembourser » and delete (each explaining its effect on stock); a refunded order shows « Remboursée le … » (in the list: a « Remboursée » badge, its total struck through and left out of the day's total); a line without product is a warning badge « Produit inconnu » with « Choisir le produit » (product and variant, in a modal).

`/events/{id}/check` (« Vérifier les commandes » on the event): the event's orders in the order they were placed, each with its time, source, payment, items and total and a tick box; a progress bar « N / M commandes vérifiées », a « Seulement les commandes à vérifier » switch, the reference opens the order to fix it. « Arrêter pour l'instant » goes back to the event (ticks are saved at once), « Passer à l'inventaire » opens the stock check; once every order is ticked, a banner offers both.
