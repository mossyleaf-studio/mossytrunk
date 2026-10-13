# Sales channels (Canaux de vente)

A workspace sells through **channels**: its markets and conventions, an Etsy shop, a stand paid with SumUp… Each channel
has its **prices** and its **orders**. They are managed on their own page, **Canaux de vente** (`/channels`, under Ventes in the sidebar).

| Field | Meaning |
|---|---|
| `name` | « Marchés », « Boutique Etsy »…, unique in the workspace (case-insensitive), max 100 characters |
| `kind` | **Marché** (`market`): every order has an event · **En ligne** (`online`): orders with or without event |
| `service` | Optional connected service (`sumup`, `etsy`…) whose imported sales go on this channel |
| `main` | The **main channel**, one per workspace: its prices are the products' **selling prices** |

Model: `src/Domain/Sales/` — `SalesChannel`, `ChannelKind`, `PriceAdjustment`; `Domain\Product\ChannelPrice` (a product's
own price on a channel); `Order::$channel`.

## Rules

| # | Rule | Where | Tests |
|---|---|---|---|
| C1 | Every workspace has one **main channel**, of kind Marché, created with the workspace (« Marchés », in the app's language). The migration that introduced channels created it for existing workspaces and put on it every existing order **with an event**; orders without event (Etsy) stay without channel. The main channel cannot be deleted | `SalesChannel::main()`, `WorkspaceOpening`, `SalesChannel::assertRemovable()`, migration `Version20261001202513` | `AccountUseCasesTest`, `SalesChannelUseCasesTest` |
| C2 | The main channel's price of a product **is** its selling price (with its history, P17). Another channel has its own price for a product or, without one, **follows the selling price**. Prices are never negative | `Product::priceOn()`, `setPriceOn()`, `followSellingPriceOn()` | `ChannelPriceTest` |
| C3 | A channel name is required and unique in the workspace; a service is a known connector and feeds **one channel at most** | `SalesChannel::rename()`, `ChannelAvailability` | `SalesChannelTest`, `SalesChannelUseCasesTest`, `SalesChannelApiTest` |
| C4 | A **Marché** channel only has orders placed during an event: an order without event is refused on it, and a channel holding orders without event cannot become a Marché | `Order::__construct()` (`MarketOrderWithoutEvent`), `UpdateChannelHandler` (`ChannelHasOrdersWithoutEvent`) | `OrderTest`, `SalesChannelUseCasesTest` |
| C5 | An order's channel: a manual order (always at an event) goes on the main channel; an imported order goes on the channel linked to its service, else on the main channel when it has an event, else on no channel. A service linked to a Marché channel looks the event up even when imported « en ligne », and a sale outside any event is reported like the « au marché » ones (not imported) | `OrderChannel`, `PlaceOrderHandler`, `ImportSalesHandler` | `OrderUseCasesTest`, `SalesChannelUseCasesTest` |
| C6 | Imported sales are **listed at the price of the channel linked to the service** (so for SumUp the gap with the price paid is a discount, I-rules), and the catalogue exported for a service carries that channel's prices | `ExternalItemResolver::sold()`, `ExportCatalogueHandler` | `ImportSumUpSalesTest`, `SalesChannelUseCasesTest` |
| C7 | Batch edit sets the price of the selection on **one channel**: a fixed amount; **from another channel's price** plus an amount in € or a percentage (rounded to the cent, half away from zero; negative to lower; 0 copies); or « Suivre les prix » of the main channel again. Any negative result aborts the batch. A price set on the **main** channel follows the batch's « Prix à partir du » day like the selling price | `ChannelRepricing`, `PriceAdjustment` | `PriceAdjustmentTest`, `SalesChannelUseCasesTest` |
| C8 | Deleting a channel forgets its prices (products keep their selling price); its orders keep their lines and lose their channel | FK `ON DELETE CASCADE` / `SET NULL` | `SalesChannelUseCasesTest` |
| C9 | A product's price can be set **on one channel at a time** (an amount, or « Suivre le prix » of the main channel again); on the main channel it reprices the product today (P17) | `SetChannelPriceHandler` → `ChannelPricing::assign()` | `SalesChannelApiTest` |
| C10 | A channel **offers supplies** (only supplies): those are the supplies its orders can use (O17). Deleting the supply or the channel forgets the offer | `SalesChannel::offerSupplies()` (`NotASupply`), `offers()` | `OrderSuppliesTest`, `OrderApiTest` |
| C11 | A channel has **costs per order** (taxes, commissions, packaging fees): a label and a fixed amount in cents or a percentage of the order total in basis points, never negative. They are charged on its orders as a snapshot (O18) | `SalesChannel::addCost()`, `reviseCost()`, `removeCost()`, `ChannelCost::on()` | `ChannelCostTest`, `SalesChannelApiTest` |

## Use cases & API

| Use case | Endpoint |
|---|---|
| `ListChannels` | `GET /api/sales-channels` → `[{id, name, service, serviceLabel, kind, main}]` (main first, then by name) |
| `GetChannel` | `GET /api/sales-channels/{id}` → `{id, name, service, serviceLabel, kind, main}` |
| `CreateChannel` / `UpdateChannel` | `POST /api/sales-channels`, `PUT /api/sales-channels/{id}` `{name, kind, service?}` |
| `DeleteChannel` | `DELETE /api/sales-channels/{id}` |
| product prices | `POST /api/products`, `PUT /api/products/{id}` take `channelPrices: [{channelId, price}]` (`null` = follow the selling price); `GET /api/products` returns `channelPrices: {channelId: cents}` (own prices only) |
| `AddChannelCost`, `ReviseChannelCost`, `RemoveChannelCost` | `POST /api/sales-channels/{id}/costs`, `PUT\|DELETE /api/sales-channels/{id}/costs/{costId}` `{label, kind: fixed\|percent, amount}` (cents or basis points); channel views carry `costs: [{id, label, kind, amount}]` |
| `OfferSupplies` | `PUT /api/sales-channels/{id}/supplies` `{supplyIds[]}`; channel views carry `supplies: [{id, name, variants}]` |
| `SetChannelPrice` | `PUT /api/products/{id}/channel-prices/{channelId}` `{price}` (`null` = follow the selling price) |
| batch | `POST /api/products/batch` takes `channelPrice: {channelId, mode: fixed\|derived\|selling_price, price?, sourceChannelId?, adjustment, adjustmentUnit: cents\|percent}` |

## UI

- **Canaux de vente** (`/channels`): one row per channel with its kind, linked service and costs summary; the main one is badged « Canal principal » and cannot be deleted. Add/edit form: name, kind (Marché / En ligne), linked service.
- **Channel page** (`/channels/{id}`): kind and linked service, edit/delete; **Frais par commande**: list, add/edit (name, amount in € or %), delete; **Fournitures**: chips of every supply, toggled to offer it (saved at once); **Prix**: every active product (searchable) with the main channel's price and its price on this channel (greyed when it follows), a pencil to set one price (amount, or « Suivre le prix {principal} »), and « Modifier les prix affichés » to apply a batch channel price (C7) to the products shown.
- **Produits**: the selling price is labelled « Prix {principal} (canal principal) » in forms; its column is headed with the main channel's name, one more column per other channel (greyed when following). The product form has one « Prix {canal} » field per other channel (empty = follows). The batch form has « Prix d'un canal » with a preview of the first products.
- **Product page**: the main channel's price and one fact per other channel, each with a pencil to set it.
- **Order page**: the order's channel next to its event; the margin card lists the channel's charges and the postage (pencil to type it); a « Fournitures » card lists its supplies (add one among the channel's, remove one). **Commandes**: « Recalculer les frais du canal » and « Ajouter une fourniture » in the selection bar (the latter when the selected orders share a channel that offers supplies). The supply form takes several supplies at once (« Une autre fourniture » adds a row, each with its variant and quantity), on the order page and for a selection.
