<?php

declare(strict_types=1);

namespace App\Tests\Functional\Application\Integration;

use App\Application\Integration\ConfigureConnection\AddConnectionHandler;
use App\Application\Integration\Exception\ServiceNotAdded;
use App\Application\Integration\Exception\ServiceNotImportingCatalogue;
use App\Application\Integration\ImportCatalogue\ImportCatalogueHandler;
use App\Application\Integration\ListExternalItems\ExternalItemView;
use App\Application\Integration\ListExternalItems\ListExternalItemsHandler;
use App\Application\Product\CreateProductType\CreateProductTypeHandler;
use App\Application\Product\ListProducts\ListProductsHandler;
use App\Application\Product\ListProducts\ProductView;
use App\Domain\Integration\UnknownItems;
use App\Domain\Product\ProductRepository;
use App\Tests\Support\ActsAsUser;
use App\Tests\Support\CreatesProducts;
use App\Tests\Support\ExternalSales;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Ulid;

final class ImportSumUpCatalogueTest extends KernelTestCase
{
    use ActsAsUser;
    use CreatesProducts;

    private const string HEADER = "Item name,Variations,Price,Tax rate (%),Track inventory?,Quantity,SKU,Description,Category\r\n";

    protected function setUp(): void
    {
        self::actAsMemberOf();
    }

    public function testLinksItemsBySkuThenByNameAndRemembersTheRest(): void
    {
        $this->connect(UnknownItems::LinkByHand);
        $print = (string) self::getContainer()->get(CreateProductTypeHandler::class)('Print', variants: ['A4', 'A5'])->id();
        $forest = self::createProduct('Forêt', 1_500, variants: ['A4', 'A5'], typeId: $print);
        $reference = self::getContainer()->get(ProductRepository::class)->get(Ulid::fromString($forest))->reference();
        self::createProduct('Mug Chat', 1_200);

        $report = self::getContainer()->get(ImportCatalogueHandler::class)('sumup', self::HEADER
            ."Affiche forêt,,,,,,,,Print\r\n"
            .",A4,15.00,,No,,$reference-A4,,\r\n"
            .",A5,15.00,,No,,$reference-A5,,\r\n"
            ."Mug Chat,,12.00,,No,,,,Mug\r\n"
            ."Bougie,,8.00,,No,,,,\r\n");

        self::assertSame([4, 3, 0, 1], [$report->itemsRead, $report->itemsLinked, $report->productsCreated, $report->itemsToLink]);
        self::assertSame([
            'affiche forêt|a4' => 'Print Forêt — A4',
            'affiche forêt|a5' => 'Print Forêt — A5',
            'bougie|' => null,
            'mug chat|' => 'Mug Chat',
        ], $this->links());
    }

    public function testCreatesTheMissingProductsWhenTheConnectionDoesSo(): void
    {
        $this->connect(UnknownItems::CreateProduct);

        $report = self::getContainer()->get(ImportCatalogueHandler::class)('sumup', self::HEADER."Bougie,,8.00,,No,,,,Déco\r\n");

        self::assertSame([1, 1, 1, 0], [$report->itemsRead, $report->itemsLinked, $report->productsCreated, $report->itemsToLink]);
        self::assertSame([['Bougie', 'Déco', 800]], array_map(static fn (ProductView $product): array => [$product->name, $product->typeName, $product->sellingPrice], self::getContainer()->get(ListProductsHandler::class)()));
    }

    public function testRequiresTheServiceToBeAddedAndToImportCatalogues(): void
    {
        try {
            self::getContainer()->get(ImportCatalogueHandler::class)('sumup', self::HEADER);
            self::fail('a service not added imported a catalogue');
        } catch (ServiceNotAdded) {
        }

        $this->expectException(ServiceNotImportingCatalogue::class);
        self::getContainer()->get(ImportCatalogueHandler::class)('etsy', self::HEADER);
    }

    private function connect(UnknownItems $unknownItems): void
    {
        ExternalSales::connect(self::getContainer()->get(AddConnectionHandler::class), 'sumup', ['merchant_code' => 'MCODE', 'api_key' => 'sup_sk_test'], unknownItems: $unknownItems);
    }

    /**
     * @return array<string, ?string>
     */
    private function links(): array
    {
        $links = [];
        foreach (self::getContainer()->get(ListExternalItemsHandler::class)('sumup') as $item) {
            $links[$item->externalRef.'|'.mb_strtolower($item->variation ?? '')] = self::linkLabel($item);
        }
        ksort($links);

        return $links;
    }

    private static function linkLabel(ExternalItemView $item): ?string
    {
        $linked = $item->components[0] ?? null;
        if (null === $linked) {
            return null;
        }

        return null === $linked['variant'] ? $linked['name'] : $linked['name'].' — '.$linked['variant'];
    }
}
