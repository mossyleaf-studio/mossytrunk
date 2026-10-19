<?php

declare(strict_types=1);

namespace App\Tests\Functional\Application\Integration;

use App\Application\Integration\CompleteAuthorization\CompleteAuthorizationHandler;
use App\Application\Integration\ConfigureConnection\AddConnectionHandler;
use App\Application\Integration\ImportSales\ImportSalesHandler;
use App\Application\Integration\ListExternalItems\ListExternalItemsHandler;
use App\Application\Integration\PublishReferences\PublishReferencesHandler;
use App\Application\Integration\ReadCatalogue\ReadCatalogueHandler;
use App\Application\Order\ListOrders\ListOrdersHandler;
use App\Application\Product\CreateProductType\CreateProductTypeHandler;
use App\Domain\Product\ProductRepository;
use App\Infrastructure\Connector\Etsy\FakeEtsyGateway;
use App\Tests\Support\ActsAsUser;
use App\Tests\Support\CreatesProducts;
use App\Tests\Support\EtsyListings;
use App\Tests\Support\ExternalSales;
use App\Tests\Support\Json;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Translation\LocaleSwitcher;
use Symfony\Component\Uid\Ulid;

final class EtsyCatalogueTest extends KernelTestCase
{
    use ActsAsUser;
    use CreatesProducts;

    private string $sticker;
    private string $print;

    protected function setUp(): void
    {
        self::getContainer()->get(LocaleSwitcher::class)->setLocale('en');
        self::actAsMemberOf();
        $types = self::getContainer()->get(CreateProductTypeHandler::class);
        $this->sticker = self::createProduct('Mousse', 400, typeId: (string) $types('Sticker')->id());
        $this->print = self::createProduct('Forêt', 1_800, variants: ['A4', 'A5'], typeId: (string) $types('Print')->id());
        ExternalSales::connect(self::getContainer()->get(AddConnectionHandler::class), 'etsy', ['keystring' => 'keystring123', 'shared_secret' => 'shared-secret']);
        self::getContainer()->get(CompleteAuthorizationHandler::class)('etsy', 'code', 'verifier', 'https://app.test/settings/etsy/callback');
    }

    public function testTheShopsListingsAreLinkedByTitleAndTheRestWaits(): void
    {
        $report = self::getContainer()->get(ReadCatalogueHandler::class)('etsy');

        self::assertSame([3, 1, 0, 2], [$report->itemsRead, $report->itemsLinked, $report->productsCreated, $report->itemsToLink]);
        $links = [];
        foreach (self::getContainer()->get(ListExternalItemsHandler::class)('etsy') as $item) {
            $links[$item->externalRef.'|'.$item->variation] = $item->components[0]['name'] ?? null;
        }
        ksort($links);
        self::assertSame(['1500000001|' => 'Sticker Mousse', '1500000002|A4' => null, '1500000002|A5' => null], $links);
    }

    public function testListingsCarryingReferencesAsSkusAreLinkedToTheirVariant(): void
    {
        $reference = self::getContainer()->get(ProductRepository::class)->get(Ulid::fromString($this->print))->reference();
        self::getContainer()->get(FakeEtsyGateway::class)->willList([
            EtsyListings::listing(1500000002, 'Affiche forêt', [['sku' => $reference.'-A4', 'values' => ['Grand']], ['sku' => $reference.'-A5', 'values' => ['Petit']]]),
        ]);

        $report = self::getContainer()->get(ReadCatalogueHandler::class)('etsy');

        self::assertSame([2, 2, 0], [$report->itemsRead, $report->itemsLinked, $report->itemsToLink]);
    }

    public function testReferencesAreWrittenAsSkusOnTheLinkedListings(): void
    {
        self::getContainer()->get(ReadCatalogueHandler::class)('etsy');
        $sticker = self::getContainer()->get(ProductRepository::class)->get(Ulid::fromString($this->sticker))->reference();

        $published = self::getContainer()->get(PublishReferencesHandler::class)('etsy');

        self::assertSame([1, 1], [$published->itemsLinked, $published->listingsUpdated]);
        $updates = self::getContainer()->get(FakeEtsyGateway::class)->updates();
        self::assertSame(['1500000001'], array_map(strval(...), array_keys($updates)));
        self::assertSame($sticker, Json::string($updates, '1500000001', 'products', 0, 'sku'));
    }

    public function testASaleWhoseSkuIsAReferenceAndAVariantSellsThatVariant(): void
    {
        $reference = self::getContainer()->get(ProductRepository::class)->get(Ulid::fromString($this->print))->reference();
        self::getContainer()->get(FakeEtsyGateway::class)->willReturn([
            ExternalSales::etsy('3100000009', new \DateTimeImmutable('2030-03-14T10:00:00Z'), [ExternalSales::listing('1500000777', 'Affiche', 1_800, sku: $reference.'-A5')]),
        ]);

        self::getContainer()->get(ImportSalesHandler::class)('etsy');

        $orders = self::getContainer()->get(ListOrdersHandler::class)();
        self::assertCount(1, $orders);
        self::assertSame(0, $orders[0]->unidentifiedLines);
    }
}
