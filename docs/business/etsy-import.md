# Etsy import

The workspace connects its Etsy shop once; its **paid receipts** then go through the generic [import](imports.md). This page lists what is specific to Etsy.

Configuration: « Paramètres › Services connectés › Ajouter un service › Etsy »: each workspace brings its own Etsy developer app — « Keystring » and « Shared secret » (stored encrypted); the app must declare the return address shown in the form, `<site>/settings/etsy/callback`. Then « Connecter la boutique ».
Defaults: sales **online** (no event), unknown items **ask me**, line prices **listed**.

Code: `src/Infrastructure/Connector/Etsy/` — `EtsyConnector` (`AuthorizingConnector`), `EtsyApiGateway`, `EtsyPayloadMapper`, `FakeEtsyGateway` (test env: fake consent that redirects straight back, fixture `tests/Fixtures/etsy/receipts.json`).

## What is read from Etsy

- OAuth 2 with PKCE (scopes `transactions_r shops_r listings_r listings_w`: a shop connected before the catalogue features must be connected again, Etsy otherwise refuses with « reconnectez la boutique »); the shop id/name and token expiry are on the service connection, the access and refresh tokens are encrypted workspace secrets.
- `GET /v3/application/shops/{shop}/receipts?was_paid=true` (pages of 100).
- `GET /v3/application/shops/{shop}/payment-account/ledger-entries` from the oldest receipt to now (30-day windows, pages of 100) and `GET /v3/application/shops/{shop}/payments?payment_ids=…` for the payments those entries name, to know the fee of each receipt (Y7).
- Mapping: receipt id = external id, reference `ETSY-<receipt id>`; line = listing id (external reference), title, SKU, variations joined with « / », quantity, price; charged = Σ lines − `discount_amt`; shipping = `total_shipping_cost`; payment « Carte ».

## Rules

| # | Rule | Where | Tests |
|---|---|---|---|
| Y1 | Connecting goes through Etsy's consent page; the `state` returned must match the one kept in the session, otherwise the connection is refused | `ConnectServiceController`, `ServiceCallbackController`, `AuthorizationFlow` | `ServiceAuthorizationTest` |
| Y1b | Saving other app keys disconnects the shop (see [imports.md](imports.md) I2) | `UpdateConnectionHandler` | `ServiceConnectionUseCasesTest` |
| Y2 | The access token (1 h) is renewed with the refresh token when it expires, and saved at once; disconnecting forgets both tokens and the shop, imported orders stay | `ConnectionSession`, `DisconnectServiceHandler` | `ImportEtsySalesTest` |
| Y3 | A receipt already imported is skipped (see I3) | `ImportSalesHandler` | `ImportEtsySalesTest` |
| Y4 | A receipt line is matched by the listing's link, else **SKU = product reference**, else **title = product display name**; a product with variants needs the variation to equal one of its variants (see I5) | `ExternalItemResolver` | `ImportEtsySalesTest`, `ImportPoliciesTest` |
| Y5 | A receipt with an unmatched line waits; its listing is remembered « à associer » (see I6). A bundle listing is linked to the products it holds, with their quantities (see I13) | `ExternalItemResolver`, `LinkExternalItemHandler` | `ImportEtsySalesTest`, `etsy.spec.js` |
| Y6 | Items at the price paid on Etsy; the Etsy discount is kept as « Remise Etsy »; the **shipping charged** is part of the order total (so of the turnover); stock is taken like any sale | `Order::imported()`, `LinePrices::Listed` | `OrderTest`, `ImportEtsySalesTest` |
| Y7 | The sale keeps the **fee Etsy took** (see I12): the opposite of the sum of the shop ledger entries tied to the receipt — by `reference_id` to the receipt, one of its transactions or its payment, or by `parent_entry_id` to such an entry (the VAT on fees) — leaving out the money of the sale itself (`PAYMENT_GROSS`, `REFUND_GROSS`), payouts (`DISBURSE…`) and the sales tax Etsy collects (`sales_tax…`): transaction, shipping, processing and offsite ads fees with their VAT, minus the fees Etsy refunded. Listing fees are tied to a listing, not a sale, and are left out. The fee stays unknown until Etsy posts the receipt's payment (usually once shipped), and a later import fills it | `EtsyFees`, `EtsyApiGateway::paidReceipts()` | `EtsyFeesTest`, `ImportEtsySalesTest` |

## Catalogue

Etsy's sales already identify an item by its **listing id** (and SKU), so links are exact. Paramètres › Services connectés › Etsy:

| # | Rule | Where | Tests |
|---|---|---|---|
| Y10 | « Importer le catalogue » reads the shop's **active listings** with their inventory (`GET /v3/application/shops/{shop}/listings?state=active&includes=Inventory`) and resolves each listing, or each of its variations (values joined « / », as receipts give them), like a sale line (I5, SKU first): it links the item a later sale of that listing will use, creates no order and moves no stock; unmatched ones are left « à associer » or created per the option (I6) | `ReadCatalogueHandler`, `CatalogueLinker`, `EtsyCatalogueMapper` | `EtsyCatalogueTest` |
| Y11 | « Écrire les références sur Etsy » (confirmed first) writes, on every listing linked to a product, its **reference as SKU** — `<reference>-<variant>` for a variation linked to a variant — through `PUT /v3/application/listings/{id}/inventory`; the inventory is sent back as read (products, property values, prices, quantities, enabled flags, processing profiles), only SKUs change, and `sku_on_property` lists the varying properties when variations get different SKUs. A listing whose SKUs are already right is not written; unlinked variations keep their SKU | `PublishReferencesHandler`, `EtsyInventorySkus` | `EtsyInventorySkusTest`, `EtsyCatalogueTest` |
| Y12 | A sale whose SKU is `<reference>-<variant>` sells that variant even on a listing never linked (see I5) | `ExternalItemResolver::bySku()` | `EtsyCatalogueTest` |
| Y13 | No listing is created on Etsy: a listing needs a category, a shipping profile and photos that MossyTrunk does not hold | — | — |

