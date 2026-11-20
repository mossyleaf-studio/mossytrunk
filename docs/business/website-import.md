# Website import

The studio sells on **its own website** (mossyleaf.studio, project `~/Sites/mossyleaf-studio/website`), paid with SumUp's hosted checkout. MossyTrunk reads the **paid orders** of that shop and runs them through the generic [import](imports.md), like Etsy's receipts. This page lists what is specific to the website. MossyTrunk is always the client: the website never calls it, and no customer data leaves the website.

Configuration: « Paramètres › Services connectés › Ajouter un service › Site web »: the **address** of the site (`https://mossyleaf.studio`) and the **token** the site expects (its `MOSSYTRUNK_TOKEN`, stored encrypted here). There is no consent page: the service is « Prêt » as soon as both are typed.
Defaults: sales **online** (no event), unknown items **ask me**, line prices **listed**.

Code: `src/Infrastructure/Connector/Website/` — `WebsiteConnector` (`SalesConnector`), `WebsiteGateway`, `WebsiteApiGateway`, `WebsitePayloadMapper`, `WebsiteAccess` (address, token and the label to name the service in errors), `FakeWebsiteGateway` (test env: fixture `tests/Fixtures/website/orders.json`, one paid order and one cancelled, whose line names are those of no product so that only the SKU can match them).

## What is read from the website

- `GET {address}/integrations/orders` with `Authorization: Bearer {token}` → `{"orders": [{"id", "reference", "placedAt", "lines": [{"sku", "name", "variant", "unitPrice", "quantity"}], "goods", "shipping", "cancelledAt"}]}`: every paid order ever, oldest first, amounts in integer cents. The website answers 401 to a wrong token and 404 while its integration is switched off.
- Mapping: `id` = external id, `reference` as given (the id when it is blank); line = `sku` (external reference and SKU), `name`, `variant`, `quantity`, `unitPrice`; charged = `goods`; shipping = `shipping`; payment « Carte »; fee 0; cancelled on `cancelledAt`.

## Rules

| # | Rule | Where | Tests |
|---|---|---|---|
| W1 | The address is an **https origin and nothing else**: `https://` and a host name with its domain, without port, path (not even a trailing `/`), query, fragment, user or password; an IP address or a name without domain is refused | `WebsiteConnector` (field pattern, container parameter `app.website.address_pattern`), `ConnectionPayload` | `ServicesApiTest` |
| W2 | The request **never reaches a private network**: a loopback, private, link-local or reserved address is refused before anything is sent, whatever name it hides behind. **No redirect is followed**, the request gives up after 20 s without an answer or 60 s in all, and the answer is asked uncompressed and dropped past **5 MB** (well over ten thousand orders) | `WebsiteApiGateway`, service `app.website.client` (Symfony `NoPrivateNetworkHttpClient`) | `WebsiteApiGatewayTest`, `WebsiteClientWiringTest` (the gateway the container builds uses that client) |
| W3 | Failures are told apart and **never repeat what the site or the network answered**: 401 or 403 → the access is refused (check the token); 404 → the integration is switched off, or the address is not the site's; any other status → unreachable, « HTTP {status} »; no answer, an answer that is too long or not the expected JSON → unreachable, naming only the host. The JSON is read **strictly**: `orders` and each `lines` are lists of objects, every order has an id and a readable date, `goods`, `shipping` and `unitPrice` are integers of cents (0 or more) and `quantity` an integer of 1 or more; a single order that is not so makes the whole answer unreadable. Nothing is imported from an answer that cannot be read | `WebsiteApiGateway`, `WebsitePayloadMapper`, `AccessRefused`, `IntegrationDisabled`, `ServiceUnreachable` | `WebsiteApiGatewayTest`, `WebsitePayloadMapperTest` |
| W4 | An order already imported is skipped (see [imports.md](imports.md) I3): the website sends every order each time | `ImportSalesHandler` | `ImportWebsiteSalesTest` |
| W5 | A line is matched by its **SKU**: the product's reference, or `<reference>-<variant>` naming one of its variants — what the catalogue published to the website carries (see I5). A line whose name is blank is named by its SKU, so that it is still matched instead of being sold without product | `WebsitePayloadMapper`, `ExternalItemResolver::bySku()` | `WebsitePayloadMapperTest`, `ImportWebsiteSalesTest` |
| W6 | An unknown SKU **waits « à associer »** and creates no product (default « ask me », see I6): a product created by the import would be published to the website by the next catalogue push | `WebsiteConnector` (`UnknownItems::LinkByHand`) | `ServiceConnectionUseCasesTest`, `ImportWebsiteSalesTest` |
| W7 | Items at the price paid on the website; a gap between the lines and `goods` is kept as « Remise Site web »; the **shipping charged** is part of the order total (so of the turnover); stock is taken like any sale | `Order::imported()`, `LinePrices::Listed` | `ImportWebsiteSalesTest` |
| W8 | The website reports **no payment fee** (SumUp's online fee is not exported). Each sale carries a fee of **zero**, which is a known fee: the order shows no « Frais Site web » and is not read again by later imports (see I12). The fee is stood in by a **cost of the channel**: an « En ligne » channel linked to the service, with a cost per order of 2.5 % ([channels.md](channels.md) C5, C11), then the only charge on the order | `WebsitePayloadMapper`, `OrderCharges` | `WebsitePayloadMapperTest`, `ImportWebsiteSalesTest` |
| W9 | An order with a `cancelledAt` is a cancelled sale (see I14): imported cancelled on that date, its units back in stock, or cancelled by the next import when it was cancelled after being imported | `WebsitePayloadMapper`, `SaleCancellations` | `WebsitePayloadMapperTest`, `ImportWebsiteSalesTest` |
| W10 | The service is named **in the user's language** (« Site web », "Website"): its name is a common noun written into backend texts (« Remise Site web », import report, orders export), so the server translates it | `WebsiteConnector::describe()`, `Application\Translator`, `messages` key `services.website.label` | `ServiceConnectionUseCasesTest` |
| W11 | The token **belongs to the address it was typed for**: saving another address without typing the token again is refused and changes nothing, so the token kept is never sent to another site. The token is made of visible ASCII characters only (no space, no accent), what a `Bearer` header can carry | `ServiceField::$bindsSecrets`, `ConnectionCredentials`, `SecretNeededAgain`, `WebsiteConnector` (token pattern) | `ServiceConnectionUseCasesTest`, `ServicesApiTest` |

## Development

`when@dev` (`config/services.yaml`) loosens the two container parameters so that a development machine reaches the website's dev stack at `http://host.docker.internal:8094`: `app.website.address_pattern` also takes `http` and a port, and `app.website.allowed_private_subnets` lets the private IPv4 ranges through. Test, e2e and production keep W1 and W2 as written; test and e2e use `FakeWebsiteGateway`.

## UI

Nothing of its own: the service shows in the picker and the generic form of « Paramètres › Services connectés » (the address carries a hint and the message of W1), is imported by « Importer les ventes » and by « Synchroniser » on Commandes, and its orders are grouped under « Boutique Site web ».
