# MossyTrunk — business documentation

MossyTrunk helps a small creative business that sells at **events** (conventions, markets) manage its sales.
Module 1 = **Order Management**. This folder is the reference for business rules: each page lists the rules,
**where they are modelled** in the code and **which tests** cover them. Update it in the same commit as any rule change.

## Pages

| Page | Covers |
|---|---|
| [accounts.md](accounts.md) | Users, workspaces, sign-in with mossyleaf accounts |
| [products.md](products.md) | Catalogue, variants, the (product, variant) tuple, buying price = last purchase price (0 = never bought) |
| [channels.md](channels.md) | Sales channels (markets, online shops) with their own prices and orders, main channel = selling price, batch pricing between channels |
| [supplies.md](supplies.md) | Supplies (packaging, flyers): stocked like products, never sold |
| [events.md](events.md) | Events, periods in Europe/Paris, no overlap, expenses |
| [orders.md](orders.md) | Orders, auto-link to the event, lines & snapshots, totals, margin |
| [discounts.md](discounts.md) | Discount rules (conditions, action, validity) and the automatic calculation |
| [event-report.md](event-report.md) | Profitability of an event: Dépenses, Commandes, URSSAF 12.8 %, Total |
| [dashboard.md](dashboard.md) | Results per month and per year |
| [product-reports.md](product-reports.md) | Product report: best sellers, sales, stock flow and discounts over time |
| [stock.md](stock.md) | Stock per sellable item in FIFO lots, low stock, inventory after an event and missing orders |
| [references.md](references.md) | Reference formats per kind (orders, supplier orders, products): tags, numbering, renumbering existing items |
| [supplier-orders.md](supplier-orders.md) | Suppliers, supplier orders, reception into stock at the real unit cost |
| [designs.md](designs.md) | Designs and collections declined onto gabarits, validated into products |
| [accounting.md](accounting.md) | URSSAF declarations per month or quarter, CSV export of orders |
| [imports.md](imports.md) | Connected services, the generic sales import (event or online, unknown items created or linked by hand) |
| [etsy-import.md](etsy-import.md) | Etsy specifics: shop connection, paid receipts, listings |
| [sumup-import.md](sumup-import.md) | SumUp specifics: payments read, categories, free amounts, variants, payment method |

## Glossary (UI term → code)

| UI (FR) | Code | Meaning |
|---|---|---|
| Espace de travail | `Domain\Identity\Workspace` | One business and all its data |
| Utilisateur | `Domain\Identity\User` | Person signing in with their mossyleaf account |
| Produit | `Domain\Product\Product` | Real item sold; unique reference |
| Type de produit | `Domain\Product\ProductType` | Print, Sticker…; product shown as « {type} {nom} » |
| Variante | `Product::$variants` (strings) | Colour, size, design…; none = *produit unique* |
| Article vendable | `Domain\Product\SellableItem` | Validated (product ULID, variant) tuple + prices at sale time |
| Marché / salon (événement) | `Domain\Event\Event` | Convention/market with an inclusive day period and a location |
| Dépense | `Domain\Event\Expense` | Money spent for an event |
| Don | `Domain\Event\Donation` | Money given at an event without a sale (donation box, support): raises its result, not its turnover nor URSSAF |
| Commande | `Domain\Order\Order` | A sale during an event |
| Ligne de commande | `Domain\Order\OrderLine` | Quantity of one tuple, with name/price/cost snapshots |
| Remise | `Domain\Discount\DiscountRule` | Conditions (quantity of a type or product) + action (prix fixe, remise en € ou en %) + validity period |
| Condition | `Domain\Discount\DiscountCondition` | `quantity × product` or `quantity × type` |
| Remise appliquée | `Domain\Discount\AppliedDiscount` | `{label, amount, ruleId}` snapshot stored on an order |
| Sous-total / Total | `Order::subtotal()` / `total()` | Before / after discounts |
| Coût d'achat | `Order::costOfGoods()` | Σ line costs (units taken from stock, oldest lot first) |
| Réserve (stock) | `Domain\Stock\StockItem` | Units of one sellable item, in lots |
| Lot | `Domain\Stock\StockLot` | Units received together at one cost |
| Canal de vente | `Domain\Sales\SalesChannel` | Where products are sold (Marché or En ligne), with its prices and orders; the main one carries the selling price |
| Fourniture | `Domain\Product\Product` (`ProductKind::Supply`) | A stocked product that is used up, never sold (packaging, flyers) |
| Format de référence | `Domain\Reference\ReferenceFormat` | How a workspace writes the references of one kind of item (text + tags, next number) |
| Fournisseur | `Domain\Purchasing\Supplier` | Who products are bought from |
| Commande fournisseur | `Domain\Purchasing\SupplierOrder` | Purchase from a supplier: ordered, then received into stock |
| Gabarit | `Domain\Design\Gabarit` | Generic support (tirage 15×15, sticker brillant…) with default prices and adaptations |
| Design / Collection | `Domain\Design\Design`, `DesignCollection` | Illustration being worked on (« sur l'établi »), alone or in a collection; « sorti de l'atelier » once validated |
| Déclinaison | `Domain\Design\Declination` | A design on a gabarit; becomes a product on validation |
| Déclaration URSSAF | `Domain\Accounting\UrssafDeclaration` | A month or quarter marked declared, with the turnover declared |
| Service connecté | `Domain\Integration\ServiceConnection` | An external service (SumUp, Etsy…) added to the workspace, with its access and import options |
| Article à associer | `Domain\Integration\ExternalItem` | A service item not matched to a product, waiting to be linked |
| Inventaire | `Domain\Stock\StockCheck` | Count after an event; missing units flag a probable missing order |
| Chiffre d'affaires | `SalesFigures::$turnover` | Σ order totals of an event |
| URSSAF | `Domain\Reporting\UrssafContribution` | 12.8 % of turnover |
| Résultat | `SalesFigures::$result` | Turnover − cost of goods − expenses − URSSAF |

## Cross-cutting conventions

- **Workspaces**: every product, type, event, order and discount belongs to one workspace; users only ever see and change the data of their own workspace, and uniqueness rules apply within a workspace (see [accounts.md](accounts.md)).
- **Money** is integer cents (`Domain\Shared\Money`); the API exchanges cents; the UI shows `12,50 €`.
- **Dates**: business time zone is Europe/Paris (`DateRange::TIMEZONE`). Events are whole days; orders are stored with their time zone.
- **Identifiers**: ULIDs everywhere (`symfony/uid`, stored as PostgreSQL `uuid`).
- **Business rule violations** throw `Domain\Shared\Exception\DomainException` subclasses (one class per violation, under `<Context>/Exception/`) with French user-facing messages, returned as HTTP 422 (`404` for `NotFound`).
- **Snapshots**: orders never depend on the current state of products or discount rules.
