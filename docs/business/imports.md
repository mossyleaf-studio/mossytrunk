# Imports from connected services

A workspace **adds the external services it uses** (SumUp, Etsy…) in « Paramètres › Services connectés », then imports
their sales from « Commandes ». Every service goes through the same import; only reading the service's API and mapping its
fields is service-specific. Service pages: [sumup-import.md](sumup-import.md), [etsy-import.md](etsy-import.md).

| Concept | Meaning |
|---|---|
| Connector | Code for one service (`Infrastructure/Connector/<Service>/`): describes its access fields, default options and how its line prices behave, and reads its sales as generic `ExternalSale`s. The field mapping is fixed in the connector |
| Service connection | A service added to a workspace (`Domain\Integration\ServiceConnection`): non-secret fields, the two options, and the authorized account for services that connect through their consent page |
| Sales context | Option: **at the day's market** (each sale joins the event covering its date) or **online** (no event) |
| Unknown items | Option: **create the product** or **ask me** (the item waits to be linked by hand) |
| External item | A service item (SumUp line name, Etsy listing + variation) that could not be matched, remembered with the product/variant it sells once linked (`Domain\Integration\ExternalItem`) |

Model: `src/Domain/Integration/`, `src/Application/Integration/` (ports `SalesConnector`, `AuthorizingConnector`, registry `Connectors`,
`ImportSales/ImportSalesHandler`, `ImportSales/ExternalItemResolver`), connectors in `src/Infrastructure/Connector/`.

## Rules

| # | Rule | Where | Tests |
|---|---|---|---|
| I1 | A service is added **once per workspace**; its access fields are required (secret fields are kept encrypted, see [accounts.md](accounts.md) A11, and only shown as ••••1234). A blank secret on edit keeps the current one | `AddConnectionHandler`, `ConnectionCredentials` | `ServiceConnectionUseCasesTest`, `ServicesApiTest` |
| I2 | Changing the access of a service that connects through its consent page **disconnects** it (the tokens belong to the previous access). Removing a service forgets its access and tokens; imported orders and external items stay | `UpdateConnectionHandler`, `RemoveConnectionHandler`, `ConnectionSession` | `ServiceConnectionUseCasesTest` |
| I3 | **Idempotent**: a sale already imported (same service and external id within the workspace, even when merged into another order) is skipped. Imported orders get an internal reference like manual ones; the service's reference is kept with the sale and shown on the order | `ImportSalesHandler`, `OrderRepository::importedExternalIds()` | `ImportSumUpSalesTest`, `ImportEtsySalesTest` |
| I4 | Sales are imported oldest first. **At the day's market**: an order joins the event covering its date (Paris day); with no event the order is not imported and its date is listed once, whatever the number of orders. **Online**: no event | `ImportSalesHandler`, `EventRepository::findCovering()` | `ImportSumUpSalesTest`, `ImportPoliciesTest` |
| I5 | A line is matched, in order: an **external item linked** to a product; **SKU = product reference**, or `<reference>-<variant>` naming one of the product's variants (what MossyTrunk's exports write: the SKU alone then picks the variant); the **display name** (« Print Forêt », case-insensitive) or, when the service gives a category, the product of that type with that name (« Forêt » in category Print, like the products an earlier import created, see S5b), or the product's own name without its type (« Black kitties Vase Sticker » for « Sticker Black kitties Vase Sticker ») when no other product has that name with its variant, a product split per variant, or `Nom - Variante` / `Nom (Variante)` / `Nom Variante` names; a line without variant naming a product with **a single variant** is sold as that variant; a line without a name is sold **without product** (« Produit inconnu », see S10) | `ExternalItemResolver` | `ImportSumUpSalesTest`, `ImportPoliciesTest` |
| I6 | An unmatched line **creates the product** (price = the service's line price, buying price 0, the category becomes the type) when the option says so; otherwise, or when the product has variants and none matches, the item is remembered « à associer » and its order waits. Once linked, the next import brings the order in | `ExternalItemResolver`, `LinkExternalItemHandler` | `ImportPoliciesTest`, `ImportEtsySalesTest` |
| I7 | Products are resolved (and created) before the event check, so a sale without event still brings its products | `ImportSalesHandler` | `ImportSumUpSalesTest` |
| I8 | The order total is what the service charged for the goods, plus the shipping it charged. The gap between the lines and that amount is the service's discount: **at the day's market**, the [discount rules](discounts.md) valid that day replace it when they explain it to within 2 cents; otherwise it becomes one « Remise {service} » | `Order::imported()`, `ImportSalesHandler` | `OrderTest`, `ImportSumUpSalesTest` |
| I9 | Line prices: a connector whose line prices may already include a spread basket discount sells each line at the **higher** of the catalogue and line prices (SumUp, S11); a connector giving list prices sells at the line price (Etsy, Y6). A product created by the import takes the highest price seen during that import | `LinePrices`, `ExternalItemResolver::sold()` | `ImportSumUpSalesTest`, `ImportPoliciesTest` |
| I10 | Imported orders take their units from stock like any sale (oldest lot first) | `StockKeeper::withdraw()` | `ImportSumUpSalesTest` |
| I11 | Every line resolved to a product (matched, created or linked by hand) **remembers its link** (service item → product and variant): later imports follow it first, so renaming the product, changing its type or moving it keeps its sales coming. A link whose product or variant is gone is dropped and the line is matched again. Links are **per service**: each service keeps its own items, a product can be linked differently on Etsy and SumUp. The links show, and can be changed, in the service's items (Paramètres › Services connectés › « Articles », collapsed « associés » part) | `ExternalItemResolver`, `ExternalItem::link()`, `MoveVariantHandler` | `ImportSumUpSalesTest` |
| I12 | A connector may report the **fee the service took** on each sale (SumUp, S14), kept with the imported sale. Once every sale of an order has its fee, the order is charged that real fee (« Frais {service} », none when it is zero) next to its channel's costs; until then it carries no payment fee. Fees are never configured on the service: they come with the sales. Each import also fills the fees still unknown on sales imported earlier and recharges those orders (`feesUpdated` in the report), so running the import once brings past orders up to date | `SaleFees`, `Order::settleSaleFee()`, `saleFees()`, `OrderCharges`, `OrderRepository::awaitingSaleFees()` | `OrderTest`, `ImportSumUpSalesTest` |
| I13 | Linked by hand, a service item can sell **several products, each with a quantity** (a bundle, a pack of 3): a sale line becomes one order line per product (quantity × line quantity), each at its catalogue or channel price on the sale's date, and the gap with what the service charged becomes the service discount (I8). A single product with quantity 1 keeps the line price (I9). A bundle sold above its products' worth records no surcharge. « Écrire les références » skips bundles (no single SKU) | `ExternalItem::linkAll()`, `ExternalItemResolver`, `PublishReferencesHandler` | `ExternalItemTest`, `ImportEtsySalesTest`, `ServicesApiTest` |

## Use cases & API

| Use case | Endpoint |
|---|---|
| `ListServices` | `GET /api/services` → every service with its fields and defaults, and the workspace's connection (values, options, status, items to link) |
| `AddConnection` | `POST /api/services` `{service, fields, salesContext?, unknownItems?}` → 201; violations on `[field]` |
| `UpdateConnection` / `RemoveConnection` | `PUT` / `DELETE /api/services/{service}` |
| `Authorize` | `GET /settings/{service}/connect` → service → `GET /settings/{service}/callback` → `/settings?service=…&connection=connected\|refused\|error\|unavailable`; `DELETE /api/services/{service}/authorization` disconnects |
| `ImportSales` | `POST /api/services/{service}/import` → `{service, label, ordersImported, ordersAlreadyImported, productsCreated, typesCreated, ordersWithoutEvent, datesWithoutEvent, ordersWaitingForItems, itemsToLink, salesWithoutItems, feesUpdated}` |
| `ListExternalItems` / `LinkExternalItem` | `GET /api/services/{service}/items`, `PUT /api/services/{service}/items/{id}` `{lines: [{productId, variant, quantity}]}` |

## Adding a service

1. `src/Infrastructure/Connector/<Service>/<Service>Connector.php` implementing `SalesConnector` (or `AuthorizingConnector`): its key, label, one-line summary, access fields, default options and `LinePrices`, and `sales()` mapping the service's payloads to `ExternalSale` / `ExternalLine`.
2. A fake gateway bound in `when@test` (`config/services.yaml`) so tests and e2e never call the real API.
3. A page in this folder for its service-specific rules. The settings form, import button and « articles à associer » screen come for free.

## UI

- **Paramètres › Services connectés**: one row per added service (status Prêt / Connecté / À connecter / Accès incomplets, options, articles à associer), « Importer les ventes » for a ready service (one error box per service listing the dates without event), « Articles » opens the service's items (each one « Associer » / « Modifier » to the product or products it sells, then « Relancer l'import {service} », available even while items remain to link — those coming from a catalogue import may never have been sold; orders holding an unlinked item keep waiting), « Connecter la boutique » / « Déconnecter », the catalogue actions the service offers (« Importer le catalogue » — a file for SumUp, read through the API for Etsy — and « Écrire les références sur Etsy »), Modifier, Retirer. « Ajouter un service » opens a picker (one tag per service, already added ones disabled), then the service's form: its access fields (and the return address to declare for services that connect through their consent page) and the two options.
- **Commandes**: « Synchroniser » imports the sales of every ready service in turn; one error box per service listing the dates without event; « N articles {service} à associer » links to Paramètres. Imported orders show the service as a badge; online orders are grouped under « Boutique {service} ».
