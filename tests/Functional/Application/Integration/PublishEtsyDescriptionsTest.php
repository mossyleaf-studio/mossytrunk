<?php

declare(strict_types=1);

namespace App\Tests\Functional\Application\Integration;

use App\Application\Integration\CompleteAuthorization\CompleteAuthorizationHandler;
use App\Application\Integration\ConfigureConnection\AddConnectionHandler;
use App\Application\Integration\Exception\MissingPermission;
use App\Application\Integration\Exception\ServiceUnavailable;
use App\Application\Integration\Exception\ServiceUnreachable;
use App\Application\Integration\LinkExternalItem\LinkExternalItemHandler;
use App\Application\Integration\ListExternalItems\ListExternalItemsHandler;
use App\Application\Integration\PublishCatalogue\DescriptionReport;
use App\Application\Integration\PublishCatalogue\PublishCatalogueHandler;
use App\Application\Integration\PublishCatalogue\PublishedCatalogue;
use App\Application\Integration\ReadCatalogue\ReadCatalogueHandler;
use App\Application\Order\RequestedLine;
use App\Application\Product\CreateProductType\CreateProductTypeHandler;
use App\Infrastructure\Connector\Etsy\FakeEtsyGateway;
use App\Infrastructure\Connector\Website\FakeWebsiteGateway;
use App\Tests\Support\ActsAsUser;
use App\Tests\Support\CreatesProducts;
use App\Tests\Support\EtsyListings;
use App\Tests\Support\ExternalSales;
use Monolog\Handler\TestHandler;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Translation\LocaleSwitcher;

final class PublishEtsyDescriptionsTest extends KernelTestCase
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
        $this->print = self::createProduct('Forêt', 1_800, variants: ['A4', 'A5'], typeId: (string) $types('Print', variants: ['A4', 'A5'])->id(), reference: 'WEB-PRINT');
        ExternalSales::connect(self::getContainer()->get(AddConnectionHandler::class), 'website', ['address' => 'https://shop.example', 'token' => 'integration-token-0123456789abcdef']);
    }

    public function testAListingLinkedToAProductGivesItItsTextInEachLanguageEtsyHolds(): void
    {
        $this->connectEtsy();
        $this->list([self::described(EtsyListings::listing(1500000001, 'Sticker Mousse', [['sku' => '', 'values' => []]]), 'fr', 'Sticker &amp; vinyle <b>mat</b>.', [
            'en-US' => ['language' => 'en-US', 'description' => 'Matte vinyl sticker.'],
            'de' => ['language' => 'de', 'description' => 'Matter Vinylaufkleber.'],
        ])]);
        self::getContainer()->get(ReadCatalogueHandler::class)('etsy');

        $published = $this->publish();

        self::assertSame([$this->sticker => ['fr' => 'Sticker & vinyle mat.', 'en' => 'Matte vinyl sticker.'], $this->print => []], $this->descriptions());
        self::assertSame([self::report(listings: 1, described: 1, notReached: 1)], self::reports($published));
    }

    public function testAListingNeverLinkedReachesItsProductByItsSku(): void
    {
        $this->connectEtsy();
        $this->list([
            self::described(EtsyListings::listing(1500000003, 'Autocollant', [['sku' => 'WEB-STICKER', 'values' => []]]), 'en', 'Moss sticker.'),
            self::described(EtsyListings::listing(1500000004, 'Affiche', [['sku' => 'WEB-PRINT-A5', 'values' => ['Petit']]]), 'fr', 'Affiche de la forêt.'),
        ]);

        $published = $this->publish();

        self::assertSame([$this->sticker => ['en' => 'Moss sticker.'], $this->print => ['fr' => 'Affiche de la forêt.']], $this->descriptions());
        self::assertSame([self::report(listings: 2, described: 2)], self::reports($published));
    }

    public function testABundleListingGivesNothing(): void
    {
        $this->connectEtsy();
        $this->list([self::described(EtsyListings::listing(1500000005, 'Lot forêt', [['sku' => '', 'values' => []]]), 'fr', 'Une affiche et deux stickers.')]);
        self::getContainer()->get(ReadCatalogueHandler::class)('etsy');
        $this->link('1500000005', null, [new RequestedLine($this->print, 'A4', 1), new RequestedLine($this->sticker, null, 2)]);

        $published = $this->publish();

        self::assertSame([$this->sticker => [], $this->print => []], $this->descriptions());
        self::assertSame([self::report(listings: 1, notReached: 2, bundles: 1)], self::reports($published));
    }

    public function testABundleLineGivesNothingWhileTheOtherLinesOfItsListingGiveTheirProduct(): void
    {
        $this->connectEtsy();
        $this->list([self::described(EtsyListings::listing(1500000007, 'Mousse ou lot', [['sku' => '', 'values' => ['Seul']], ['sku' => '', 'values' => ['Lot']]]), 'fr', 'Seul ou en lot.')]);
        self::getContainer()->get(ReadCatalogueHandler::class)('etsy');
        $this->link('1500000007', 'Seul', [new RequestedLine($this->sticker, null, 1)]);
        $this->link('1500000007', 'Lot', [new RequestedLine($this->print, 'A4', 1), new RequestedLine($this->sticker, null, 1)]);

        $published = $this->publish();

        self::assertSame([$this->sticker => ['fr' => 'Seul ou en lot.'], $this->print => []], $this->descriptions());
        self::assertSame([self::report(listings: 1, described: 1, notReached: 1, bundles: 1)], self::reports($published));
    }

    public function testAPackOfOneProductGivesNothing(): void
    {
        $this->connectEtsy();
        $this->list([self::described(EtsyListings::listing(1500000008, 'Trois stickers', [['sku' => '', 'values' => []]]), 'fr', 'Trois stickers Mousse.')]);
        self::getContainer()->get(ReadCatalogueHandler::class)('etsy');
        $this->link('1500000008', null, [new RequestedLine($this->sticker, null, 3)]);

        $published = $this->publish();

        self::assertSame([$this->sticker => [], $this->print => []], $this->descriptions());
        self::assertSame([self::report(listings: 1, notReached: 2, bundles: 1)], self::reports($published));
    }

    public function testAProductWhoseVariantsAreLinkedOneByOneToTheVariationsOfAListingTakesItsText(): void
    {
        $this->connectEtsy();
        $this->list([self::described(EtsyListings::listing(1500000002, 'Affiche forêt', [['sku' => '', 'values' => ['Grand']], ['sku' => '', 'values' => ['Petit']]]), 'fr', 'Affiche de la forêt.')]);
        self::getContainer()->get(ReadCatalogueHandler::class)('etsy');
        $this->link('1500000002', 'Grand', [new RequestedLine($this->print, 'A4', 1)]);
        $this->link('1500000002', 'Petit', [new RequestedLine($this->print, 'A5', 1)]);

        $this->publish();

        self::assertSame(['fr' => 'Affiche de la forêt.'], $this->descriptions()[$this->print]);
    }

    public function testVariationsLinkedToTwoProductsGiveTheTextToEach(): void
    {
        $this->connectEtsy();
        $this->list([self::described(EtsyListings::listing(1500000006, 'Mousse et forêt', [['sku' => 'WEB-STICKER', 'values' => ['Sticker']], ['sku' => 'WEB-PRINT-A4', 'values' => ['Affiche']]]), 'fr', 'Au choix.')]);

        $published = $this->publish();

        self::assertSame([$this->sticker => ['fr' => 'Au choix.'], $this->print => ['fr' => 'Au choix.']], $this->descriptions());
        self::assertSame([self::report(listings: 1, described: 2)], self::reports($published));
    }

    public function testAListingWhoseThreeVariationsAreLinkedToThreeProductsFillsTheThree(): void
    {
        $pin = self::createProduct('Lichen', 600, typeId: (string) self::getContainer()->get(CreateProductTypeHandler::class)('Pin')->id(), reference: 'WEB-PIN');
        $this->connectEtsy();
        $this->list([self::described(EtsyListings::listing(1500000009, 'Choisissez votre motif', [['sku' => '', 'values' => ['Motif 1']], ['sku' => '', 'values' => ['Motif 2']], ['sku' => '', 'values' => ['Motif 3']]]), 'fr', 'Un motif au choix.')]);
        self::getContainer()->get(ReadCatalogueHandler::class)('etsy');
        $this->link('1500000009', 'Motif 1', [new RequestedLine($this->sticker, null, 1)]);
        $this->link('1500000009', 'Motif 2', [new RequestedLine($this->print, 'A5', 1)]);
        $this->link('1500000009', 'Motif 3', [new RequestedLine($pin, null, 1)]);

        $published = $this->publish();

        self::assertSame(['fr' => 'Un motif au choix.'], $this->descriptions()[$this->sticker]);
        self::assertSame(['fr' => 'Un motif au choix.'], $this->descriptions()[$this->print]);
        self::assertSame(['fr' => 'Un motif au choix.'], $this->descriptions()[$pin]);
        self::assertSame([self::report(listings: 1, described: 3)], self::reports($published));
    }

    public function testAProductWithItsOwnListingTakesItsTextOverAListingSharedWithOtherProducts(): void
    {
        $this->connectEtsy();
        $this->list([
            self::described(EtsyListings::listing(1500000010, 'Mousse et forêt', [['sku' => 'WEB-STICKER', 'values' => ['Sticker']], ['sku' => 'WEB-PRINT-A4', 'values' => ['Affiche']]]), 'fr', 'Au choix.'),
            self::described(EtsyListings::listing(1500000020, 'Sticker Mousse', [['sku' => 'WEB-STICKER', 'values' => []]]), 'fr', 'Le sticker Mousse.'),
        ]);

        $published = $this->publish();

        self::assertSame([$this->sticker => ['fr' => 'Le sticker Mousse.'], $this->print => ['fr' => 'Au choix.']], $this->descriptions());
        self::assertSame([self::report(listings: 2, described: 2)], self::reports($published));
    }

    public function testAProductReachedByTwoListingsTakesTheOneWithTheLowestListingId(): void
    {
        $this->connectEtsy();
        $this->list([
            self::described(EtsyListings::listing(1500000010, 'Sticker Mousse grand', [['sku' => 'WEB-STICKER', 'values' => []]]), 'fr', 'La seconde annonce.'),
            self::described(EtsyListings::listing(999000001, 'Sticker Mousse', [['sku' => 'WEB-STICKER', 'values' => []]]), 'en', 'The first listing.'),
        ]);

        $published = $this->publish();

        self::assertSame(['en' => 'The first listing.'], $this->descriptions()[$this->sticker]);
        self::assertSame([self::report(listings: 2, described: 1, notReached: 1)], self::reports($published));
    }

    public function testWithoutEtsyTheCatalogueLeavesWithoutDescriptionsAndNothingIsSaidOfThem(): void
    {
        $published = $this->publish();

        self::assertSame([2, 2, 0, 0], [$published->products, $published->created, $published->updated, $published->retired]);
        self::assertSame([$this->sticker => [], $this->print => []], $this->descriptions());
        self::assertSame([], $published->descriptions);
    }

    public function testAnEtsyShopNotConnectedYetGivesNoDescriptionAndNothingIsSaidOfThem(): void
    {
        ExternalSales::connect(self::getContainer()->get(AddConnectionHandler::class), 'etsy', ['keystring' => 'keystring123', 'shared_secret' => 'shared-secret']);
        $this->list([self::described(EtsyListings::listing(1500000003, 'Autocollant', [['sku' => 'WEB-STICKER', 'values' => []]]), 'en', 'Moss sticker.')]);

        $published = $this->publish();

        self::assertSame([2, 2, 0, 0], [$published->products, $published->created, $published->updated, $published->retired]);
        self::assertSame([$this->sticker => [], $this->print => []], $this->descriptions());
        self::assertSame([], $published->descriptions);
    }

    #[DataProvider('etsyFailures')]
    public function testAnEtsyThatFailsLetsTheCatalogueLeaveWithoutDescriptionsAndSaysEtsyCouldNotBeRead(ServiceUnavailable $failure): void
    {
        $this->connectEtsy();
        self::getContainer()->get(FakeEtsyGateway::class)->willFailDescribing($failure);
        $logs = $this->recordLogs();

        $published = $this->publish();

        self::assertSame([2, 2, 0, 0], [$published->products, $published->created, $published->updated, $published->retired]);
        self::assertSame([$this->sticker => [], $this->print => []], $this->descriptions());
        self::assertSame([self::report(read: false)], self::reports($published));
        $warnings = array_values(array_filter($logs->getRecords(), static fn ($record): bool => str_contains($record->message, 'descriptions')));
        self::assertCount(1, $warnings);
        self::assertSame(['WARNING', ['service' => 'etsy', 'failure' => $failure::class]], [$warnings[0]->level->getName(), $warnings[0]->context]);
    }

    /**
     * @return iterable<string, array{ServiceUnavailable}>
     */
    public static function etsyFailures(): iterable
    {
        yield 'authorisation refused' => [new MissingPermission('Etsy')];
        yield 'unreachable or unreadable' => [new ServiceUnreachable('Etsy', 'api.etsy.com')];
    }

    public function testListingsWhoseTextsCannotBeReadGiveNothing(): void
    {
        $this->connectEtsy();
        $this->list([['listing_id' => 1500000003, 'title' => 'Autocollant', 'language' => 7, 'description' => ['Moss'], 'translations' => 'none', 'inventory' => ['products' => [['sku' => 'WEB-STICKER', 'property_values' => [], 'offerings' => []]]]]]);

        $published = $this->publish();

        self::assertSame([$this->sticker => [], $this->print => []], $this->descriptions());
        self::assertSame([self::report(listings: 1, notReached: 1, withoutText: 1)], self::reports($published));
    }

    public function testListingsReadThatGiveNoDescriptionAreCountedByWhatTheyLack(): void
    {
        $this->connectEtsy();
        $this->list([
            EtsyListings::listing(1500000003, 'Autocollant', [['sku' => 'WEB-STICKER', 'values' => []]]) + ['translations' => []],
            self::described(EtsyListings::listing(1500000004, 'Carte postale', [['sku' => '', 'values' => []]]), 'fr', 'Une carte postale.'),
            self::described(EtsyListings::listing(1500000005, 'Lot forêt', [['sku' => '', 'values' => []]]), 'fr', 'Une affiche et un sticker.'),
        ]);
        self::getContainer()->get(ReadCatalogueHandler::class)('etsy');
        $this->link('1500000005', null, [new RequestedLine($this->print, 'A4', 1), new RequestedLine($this->sticker, null, 1)]);

        $published = $this->publish();

        self::assertSame([$this->sticker => [], $this->print => []], $this->descriptions());
        self::assertSame([self::report(listings: 3, notReached: 1, withoutText: 1, unlinked: 1, bundles: 1)], self::reports($published));
    }

    #[DataProvider('undeclaredLanguages')]
    public function testAListingWhoseLanguageIsNotDeclaredKeepsItsTextUnderTheLanguageTheTextShows(?string $language): void
    {
        $this->connectEtsy();
        $listing = EtsyListings::listing(1500000003, 'Autocollant', [['sku' => 'WEB-STICKER', 'values' => []]]) + ['description' => 'A matte sticker for your laptop, cut in the studio.', 'translations' => []];
        $this->list([null === $language ? $listing : $listing + ['language' => $language]]);

        $published = $this->publish();

        self::assertSame(['en' => 'A matte sticker for your laptop, cut in the studio.'], $this->descriptions()[$this->sticker]);
        self::assertSame([self::report(listings: 1, described: 1, notReached: 1)], self::reports($published));
    }

    /**
     * @return iterable<string, array{?string}>
     */
    public static function undeclaredLanguages(): iterable
    {
        yield 'no language' => [null];
        yield 'an empty language' => [''];
        yield 'another language' => ['de'];
    }

    /**
     * @return array<string, bool|int|string>
     */
    private static function report(bool $read = true, int $listings = 0, int $described = 0, int $notReached = 0, int $withoutText = 0, int $unlinked = 0, int $bundles = 0): array
    {
        return ['label' => 'Etsy', 'read' => $read, 'listings' => $listings, 'described' => $described, 'notReached' => $notReached, 'withoutText' => $withoutText, 'unlinked' => $unlinked, 'bundles' => $bundles];
    }

    /**
     * @return list<array<string, bool|int|string>>
     */
    private static function reports(PublishedCatalogue $published): array
    {
        return array_map(static fn (DescriptionReport $report): array => ['label' => $report->label] + self::report($report->read, $report->listings, $report->described, $report->notReached, $report->withoutText, $report->unlinked, $report->bundles), $published->descriptions);
    }

    private function recordLogs(): TestHandler
    {
        $handler = new TestHandler();
        self::getContainer()->get('logger')->pushHandler($handler);

        return $handler;
    }

    private function connectEtsy(): void
    {
        ExternalSales::connect(self::getContainer()->get(AddConnectionHandler::class), 'etsy', ['keystring' => 'keystring123', 'shared_secret' => 'shared-secret']);
        self::getContainer()->get(CompleteAuthorizationHandler::class)('etsy', 'code', 'verifier', 'https://app.test/settings/etsy/callback');
    }

    /**
     * @param list<array<string, mixed>> $listings
     */
    private function list(array $listings): void
    {
        self::getContainer()->get(FakeEtsyGateway::class)->willList($listings);
    }

    /**
     * @param array<string, mixed>                 $listing
     * @param array<string, array<string, string>> $translations
     *
     * @return array<string, mixed>
     */
    private static function described(array $listing, string $language, string $description, array $translations = []): array
    {
        return $listing + ['language' => $language, 'description' => $description, 'translations' => $translations];
    }

    /**
     * @param list<RequestedLine> $lines
     */
    private function link(string $listingId, ?string $variation, array $lines): void
    {
        $item = array_find(self::getContainer()->get(ListExternalItemsHandler::class)('etsy'), static fn ($item): bool => $item->externalRef === $listingId && $item->variation === $variation);
        self::assertNotNull($item);
        self::getContainer()->get(LinkExternalItemHandler::class)('etsy', $item->id, $lines);
    }

    private function publish(): PublishedCatalogue
    {
        return self::getContainer()->get(PublishCatalogueHandler::class)('website');
    }

    /**
     * @return array<string, array<string, string>>
     */
    private function descriptions(): array
    {
        $descriptions = [];
        foreach (self::getContainer()->get(FakeWebsiteGateway::class)->published() as $product) {
            $descriptions[$product->id] = $product->descriptions;
        }
        ksort($descriptions);

        return $descriptions;
    }
}
