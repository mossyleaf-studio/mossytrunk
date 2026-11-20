<?php

declare(strict_types=1);

namespace App\Tests\Functional\Application\Integration;

use App\Application\Integration\ConfigureConnection\AddConnectionHandler;
use App\Application\Integration\Exception\EmptyCatalogue;
use App\Application\Integration\Exception\ServiceNotPublishingCatalogue;
use App\Application\Integration\ImportSales\ImportSalesHandler;
use App\Application\Integration\PublishCatalogue\PublishCatalogueHandler;
use App\Application\Integration\PublishCatalogue\PublishedCatalogue;
use App\Application\Integration\StockedItem;
use App\Application\Integration\StockedProduct;
use App\Application\Product\ArchiveProduct\ArchiveProductHandler;
use App\Application\Product\CreateProduct\CreateProduct;
use App\Application\Product\CreateProduct\CreateProductHandler;
use App\Application\Product\CreateProductType\CreateProductTypeHandler;
use App\Application\Product\SetChannelPrice\SetChannelPriceHandler;
use App\Application\Sales\CreateChannel\CreateChannelHandler;
use App\Application\Stock\Restock\Restock;
use App\Application\Stock\Restock\RestockHandler;
use App\Domain\Product\ProductKind;
use App\Domain\Product\ProductRepository;
use App\Domain\Sales\ChannelKind;
use App\Infrastructure\Connector\Website\FakeWebsiteGateway;
use App\Tests\Support\ActsAsUser;
use App\Tests\Support\CreatesProducts;
use App\Tests\Support\DomainExceptions;
use App\Tests\Support\ExternalSales;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Translation\LocaleSwitcher;
use Symfony\Component\Uid\Ulid;

final class PublishCatalogueTest extends KernelTestCase
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
        $this->sticker = self::createProduct('Mousse', 400, typeId: (string) $types('Sticker')->id(), reference: 'WEB-STICKER');
        $this->print = self::createProduct('Forêt', 1_800, variants: ['A4', 'A3'], typeId: (string) $types('Print', variants: ['A4', 'A3'])->id(), reference: 'WEB-PRINT');
        ExternalSales::connect(self::getContainer()->get(AddConnectionHandler::class), 'website', ['address' => 'https://shop.example', 'token' => 'integration-token-0123456789abcdef']);
    }

    public function testEachProductIsSentWithItsIdItsNamesAndItsStockPerVariant(): void
    {
        self::restock($this->sticker, null, 12);
        self::restock($this->print, 'A3', 4);

        $published = $this->publish();

        self::assertSame(['Website', 2, 2, 0, 0], [$published->label, $published->products, $published->created, $published->updated, $published->retired]);
        self::assertSame(
            [
                [$this->print, 'WEB-PRINT', 'Forêt', 'Print', 'Print Forêt', 1_800, [['A4', 'WEB-PRINT-A4', 0], ['A3', 'WEB-PRINT-A3', 4]]],
                [$this->sticker, 'WEB-STICKER', 'Mousse', 'Sticker', 'Sticker Mousse', 400, [[null, 'WEB-STICKER', 12]]],
            ],
            $this->sent(),
        );
    }

    public function testThePriceIsTheOneOfTheChannelLinkedToTheService(): void
    {
        $channel = (string) self::getContainer()->get(CreateChannelHandler::class)('Boutique du site', ChannelKind::Online, 'website');
        self::getContainer()->get(SetChannelPriceHandler::class)($this->print, $channel, 2_000);

        $this->publish();

        self::assertSame(['WEB-PRINT' => 2_000, 'WEB-STICKER' => 400], array_column($this->sent(), 5, 1));
    }

    public function testANegativeOnHandIsSentAsZero(): void
    {
        self::getContainer()->get(ImportSalesHandler::class)('website');

        $this->publish();

        self::assertSame(
            ['WEB-PRINT' => [['A4', 'WEB-PRINT-A4', 0], ['A3', 'WEB-PRINT-A3', 0]], 'WEB-STICKER' => [[null, 'WEB-STICKER', 0]]],
            array_column($this->sent(), 6, 1),
        );
    }

    public function testAnArchivedProductASupplyAndAProductWithoutActiveVariantAreLeftOut(): void
    {
        self::getContainer()->get(ArchiveProductHandler::class)($this->sticker);
        self::getContainer()->get(CreateProductHandler::class)(new CreateProduct('Pochette', 0, kind: ProductKind::Supply));
        $card = self::createProduct('Carte', 300, variants: ['Mat'], typeId: (string) self::getContainer()->get(CreateProductTypeHandler::class)('Carte', variants: ['Mat'])->id(), reference: 'WEB-CARD');
        self::getContainer()->get(ProductRepository::class)->get(Ulid::fromString($card))->type()->archiveVariants(['Mat']);
        self::getContainer()->get(EntityManagerInterface::class)->flush();

        $published = $this->publish();

        self::assertSame(1, $published->products);
        self::assertSame(['WEB-PRINT'], array_column($this->sent(), 1));
    }

    public function testAnArchivedVariantIsNotSent(): void
    {
        self::getContainer()->get(ProductRepository::class)->get(Ulid::fromString($this->print))->type()->archiveVariants(['A3']);
        self::getContainer()->get(EntityManagerInterface::class)->flush();

        $this->publish();

        self::assertSame([['A4', 'WEB-PRINT-A4', 0]], array_column($this->sent(), 6, 1)['WEB-PRINT']);
    }

    public function testAProductGoneFromTheCatalogueIsRetiredByTheNextPublication(): void
    {
        $this->publish();
        self::getContainer()->get(ArchiveProductHandler::class)($this->sticker);

        $second = $this->publish();

        self::assertSame([1, 0, 1, 1], [$second->products, $second->created, $second->updated, $second->retired]);
    }

    public function testAnEmptyCatalogueIsRefusedAndNothingIsSent(): void
    {
        self::getContainer()->get(ArchiveProductHandler::class)($this->sticker);
        self::getContainer()->get(ArchiveProductHandler::class)($this->print);

        DomainExceptions::assertThrown(new EmptyCatalogue('Website'), fn (): PublishedCatalogue => $this->publish());

        self::assertSame([], self::getContainer()->get(FakeWebsiteGateway::class)->published());
    }

    public function testOnlyAServiceThatPublishesCataloguesCanBeAsked(): void
    {
        DomainExceptions::assertThrown(new ServiceNotPublishingCatalogue('SumUp'), static fn (): PublishedCatalogue => self::getContainer()->get(PublishCatalogueHandler::class)('sumup'));
    }

    private function publish(): PublishedCatalogue
    {
        return self::getContainer()->get(PublishCatalogueHandler::class)('website');
    }

    private static function restock(string $productId, ?string $variant, int $quantity): void
    {
        self::getContainer()->get(RestockHandler::class)(new Restock($productId, $variant, $quantity, $quantity * 100));
    }

    /**
     * @return list<array{string, string, string, string, string, int, list<array{?string, string, int}>}>
     */
    private function sent(): array
    {
        $sent = array_map(
            static fn (StockedProduct $product): array => [
                $product->id,
                $product->reference,
                $product->name,
                $product->category,
                $product->displayName,
                $product->price->amount(),
                array_map(static fn (StockedItem $item): array => [$item->variant, $item->sku, $item->available], $product->items),
            ],
            self::getContainer()->get(FakeWebsiteGateway::class)->published(),
        );
        usort($sent, static fn (array $a, array $b): int => $a[1] <=> $b[1]);

        return $sent;
    }
}
