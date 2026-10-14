# SumUp import

SumUp is the card terminal used at events. Its sales go through the generic [import](imports.md); this page lists what is specific to SumUp.

Configuration: « Paramètres › Services connectés › Ajouter un service › SumUp »: merchant code and API key (SumUp dashboard → API keys, `sup_sk_…`, stored encrypted).
Defaults: sales **at the day's market**, unknown items **create the product**, line prices **may be discounted**.
Trigger: « Importer depuis SumUp » on `/orders` → `POST /api/services/sumup/import`.

Code: `src/Infrastructure/Connector/SumUp/` — `SumUpConnector`, `SumUpApiGateway` (real API), `SumUpPayloadMapper`, `FakeSumUpGateway` (test env, fixture `tests/Fixtures/sumup/transactions.json`), `ShowSumUpTransactionCommand` (`app:sumup:transaction <code> --workspace=…`).

## What is read from SumUp

- `GET /v2.1/merchants/{code}/transactions/history?statuses[]=SUCCESSFUL&types[]=PAYMENT` (all pages, `links[rel=next]`)
- `GET /v2.1/merchants/{code}/transactions?id=…` for each payment → `events[]` (the `PAYOUT` events carry SumUp's `fee_amount`, see S14) and `products[]` (`name`, `description` = variant (trimmed), `price_with_vat`, `quantity`, and `category` / `category_name` **when present** — not documented by SumUp, read defensively)
- Mapping: transaction code = external id and order reference; charged = `amount − tip_amount`; no shipping; line name = the item (lowercased as its external reference). Euros are converted to cents.

## Rules

| # | Rule | Where | Tests |
|---|---|---|---|
| S1 | Only successful payments are imported (no refunds, failed or cancelled) | `SumUpApiGateway` query filters | `SumUpApiGatewayTest` |
| S2 | Idempotent on the transaction code (see [imports.md](imports.md) I3) | `ImportSalesHandler` | `ImportSumUpSalesTest`, e2e `sumup.spec.js` |
| S5b | When SumUp gives a **category**, it becomes the created product's **type** (found case-insensitively or created), and a leading « {category} » is removed from the name: « Print Forêt » in category Print → type Print + name « Forêt » | `ImportedCatalogue::createFor()` | `ImportSumUpSalesTest`, `SumUpPayloadMapperTest` |
| S7 | A line naming a product **with variants** but no recognisable variant is remembered « à associer »; its order waits until the item is linked (see I6) | `ExternalItemResolver` | `ImportSumUpSalesTest` |
| S8 | SumUp's discount is the gap between the lines and the amount charged; the day's discount rules replace it within 2 cents (see I8) | `Order::imported()` | `OrderTest`, `ImportSumUpSalesTest` |
| S10 | An **amount typed on the terminal** (a line without a name, or that SumUp names « Custom amount ») is imported as an order line **without product** (« Produit inconnu », flagged on the order), at the line's own SumUp price: it counts in the turnover but creates no product, takes no stock, has no cost and no discount condition matches it. Such a line can be linked to a product later from the order page, or split into several products when one typed amount paid for several items (see [orders.md](orders.md) O13, O13b). The « Montant libre » / « Custom amount » products earlier imports created were removed and their lines turned into such lines | `SumUpPayloadMapper::name()`, `ExternalItemResolver`, `SellableItem::unknown()` | `SumUpPayloadMapperTest`, `ImportSumUpSalesTest` |
| S11 | SumUp spreads a basket discount over the lines: a line is sold at the **higher** of the product's price and SumUp's line price (see I9) | `LinePrices::MayBeDiscounted` | `ImportSumUpSalesTest` |
| S12 | A product's `description` is its **variant**: a variant of the named product; a product **split per variant** (`<name> <variant>`, `<name> - <variant>`, `<name> (<variant>)` without variants of its own); otherwise, when creating products, a new variant learnt by the named product when it has variants or was created by this import. On another existing product without variants the description is ignored | `SumUpPayloadMapper::variant()`, `ExternalItemResolver` | `SumUpPayloadMapperTest`, `ImportSumUpSalesTest` |
| S13 | The order keeps SumUp's **payment method**: `payment_type` `CASH` → « Espèces », any other value (`POS`, `ECOM`…) → « Carte »; unknown when SumUp sends none | `SumUpPayloadMapper::paymentMethod()` | `SumUpPayloadMapperTest`, `ImportSumUpSalesTest` |
| S14 | The sale keeps the **fee SumUp took**: the sum of the `fee_amount` of its `PAYOUT` events (SumUp reports it once the payment is paid out, usually the next day); a cash payment costs no fee; a card payment not paid out yet keeps an unknown fee until a later import reads it (see I12). `app:sumup:transaction <code> --workspace=<name>` prints the `events` to check what SumUp returns | `SumUpPayloadMapper::fee()` | `SumUpPayloadMapperTest`, `ImportSumUpSalesTest` |

## Catalogue export

SumUp offers no catalogue API: Produits › « Exporter pour SumUp » downloads a CSV to import in SumUp (Articles › Importer). Its names are the ones the sales import recognises, so sold items come back linked.

| # | Rule | Where | Tests |
|---|---|---|---|
| S20 | The export lists every **non-archived** product (neither it nor its type archived), with its **active** variants only, sorted by type then name | `ExportCatalogueHandler` | `ExportCatalogueTest` |
| S21 | An item is named by the product's **display name** (type prefix included when the type prefixes names, see I5), its **category** is the type name and its **SKU** the product reference (`<reference>-<variant>` per variant) | `ExportCatalogueHandler`, `SumUpCatalogueCsv` | `ExportCatalogueTest`, `SumUpCatalogueCsvTest` |
| S22 | Each variant is a **Variations** row under its item row, at the product's selling price (variants share one price); a product without variants is a single row | `SumUpCatalogueCsv` | `SumUpCatalogueCsvTest` |
| S23 | No tax rate (SumUp applies the account's default) and **no inventory tracking**: stock stays managed in MossyTrunk | `SumUpCatalogueCsv` | `SumUpCatalogueCsvTest` |
| S24 | SumUp's import template format: comma-separated UTF-8, prices with a dot (`12.50`), English headers (`Item name`, `Variations`, `Price`, `Tax rate (%)`, `Track inventory?`, `Quantity`, `SKU`, `Description`, `Category`) | `SumUpCatalogueCsv` | `SumUpCatalogueCsvTest` |

## Catalogue import

SumUp's sales name an item only by its name and variant text (no id, no SKU), so a sale is recognised either by matching names or through a **remembered link** (see [imports.md](imports.md) I11). Paramètres › Services connectés › SumUp › « Importer le catalogue » reads SumUp's catalogue export (Articles › Exporter) and links every item **before** its first sale.

| # | Rule | Where | Tests |
|---|---|---|---|
| S25 | Each catalogue item, or each of its variants, is resolved like a sale line: an item already linked keeps its link; otherwise its **SKU** as a product reference (`<reference>-<variant>` written by the export reads back as the reference), then the names (I5); unmatched items are created or remembered « à associer » according to the connection's option (I6) | `ImportCatalogueHandler`, `ExternalItemResolver` | `ImportSumUpCatalogueTest` |
| S26 | The link is stored under the key a sale of that item produces (lowercased name + variant), so the next sales import follows it whatever the names: a SumUp item named « Affiche forêt » with SKU `PRT-002-A4` sells as « Print Forêt — A4 » | `SumUpCatalogueReader`, `ExternalItem::keyOf()` | `ImportSumUpCatalogueTest` |
| S27 | The file is read leniently: comma, semicolon or tab separated, UTF-8 (BOM allowed) or Windows-1252, English or French headers (`Item name`/`Nom de l'article`, `Variations`/`Variante`, `SKU`, `Category`/`Catégorie`, `Price`/`Prix`), variants either on rows under their item or with the item name repeated; a file without an item name column is refused | `SumUpCatalogueReader` | `SumUpCatalogueReaderTest` |
| S28 | Importing a catalogue imports no order and moves no stock; it requires SumUp to be added (5 MB at most) | `ImportCatalogueController`, `ImportCatalogueHandler` | `ImportSumUpCatalogueTest`, `ServicesApiTest` |
