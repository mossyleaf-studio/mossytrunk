<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Integration;

use App\Domain\Integration\Exception\EmptyItemLink;
use App\Domain\Integration\Exception\NonPositiveLinkedQuantity;
use App\Domain\Integration\ExternalItem;
use App\Domain\Integration\ExternalItemComponent;
use App\Domain\Product\Product;
use App\Domain\Shared\Money;
use App\Tests\Support\TestProductType;
use App\Tests\Support\TestWorkspace;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Ulid;

final class ExternalItemTest extends TestCase
{
    public function testTheKeyIgnoresTheCaseAndSpacesOfTheVariation(): void
    {
        self::assertSame(ExternalItem::keyOf('7', ' Noir '), ExternalItem::keyOf('7', 'noir'));
        self::assertNotSame(ExternalItem::keyOf('7', 'Noir'), ExternalItem::keyOf('7', null));
    }

    public function testASeenItemWaitsToBeLinkedThenRemembersItsProduct(): void
    {
        $item = ExternalItem::seen(TestWorkspace::get(), 'etsy', '7', 'Tote bag', 'Noir', new \DateTimeImmutable('2030-01-01'));
        self::assertFalse($item->isLinked());

        $tote = Product::create(TestWorkspace::get(), 'TOTE', 'Tote', Money::cents(1_500), TestProductType::get(), ['Noir', 'Vert']);
        $item->link($tote->sellable('Noir'));

        self::assertTrue($item->isLinked());
        self::assertTrue($item->isSingle());
        self::assertEquals([[$tote->id(), 'Noir', 1]], self::components($item));
        self::assertSame(['etsy', '7|noir'], [$item->service(), $item->itemKey()]);
    }

    public function testABundleLinksToSeveralProductsWithTheirQuantities(): void
    {
        $item = ExternalItem::seen(TestWorkspace::get(), 'etsy', '8', 'Lot tote + stickers', null, new \DateTimeImmutable('2030-01-01'));
        $tote = Product::create(TestWorkspace::get(), 'TOTE', 'Tote', Money::cents(1_500), TestProductType::get(), ['Noir', 'Vert']);
        $sticker = Product::create(TestWorkspace::get(), 'STI', 'Sticker', Money::cents(400), TestProductType::get());

        $item->linkAll([[$tote->sellable('Vert'), 1], [$sticker->sellable(null), 3]]);

        self::assertFalse($item->isSingle());
        self::assertEquals([[$tote->id(), 'Vert', 1], [$sticker->id(), null, 3]], self::components($item));
    }

    public function testABundleNeedsAProductAndPositiveQuantities(): void
    {
        $item = ExternalItem::seen(TestWorkspace::get(), 'etsy', '8', 'Lot', null, new \DateTimeImmutable('2030-01-01'));
        $sticker = Product::create(TestWorkspace::get(), 'STI', 'Sticker', Money::cents(400), TestProductType::get());

        try {
            $item->linkAll([]);
            self::fail('An empty link was accepted.');
        } catch (EmptyItemLink) {
        }

        $this->expectException(NonPositiveLinkedQuantity::class);
        $item->linkAll([[$sticker->sellable(null), 0]]);
    }

    public function testMovingAVariantRelinksOnlyItsComponent(): void
    {
        $item = ExternalItem::seen(TestWorkspace::get(), 'etsy', '8', 'Lot', null, new \DateTimeImmutable('2030-01-01'));
        $tote = Product::create(TestWorkspace::get(), 'TOTE', 'Tote', Money::cents(1_500), TestProductType::get(), ['Noir', 'Vert']);
        $sticker = Product::create(TestWorkspace::get(), 'STI', 'Sticker', Money::cents(400), TestProductType::get());
        $bag = Product::create(TestWorkspace::get(), 'BAG', 'Sac', Money::cents(2_000), TestProductType::get());
        $item->linkAll([[$tote->sellable('Vert'), 1], [$sticker->sellable(null), 2]]);

        $item->relink($tote->id(), 'Noir', $bag->sellable(null));
        $item->relink($tote->id(), 'vert', $bag->sellable(null));

        self::assertEquals([[$sticker->id(), null, 2], [$bag->id(), null, 1]], self::components($item));
    }

    public function testSeeingItAgainKeepsTheLatestLabel(): void
    {
        $item = ExternalItem::seen(TestWorkspace::get(), 'sumup', 'forêt', 'Forêt', null, new \DateTimeImmutable('2030-01-01'));

        $item->seenAgain('Forêt (nouveau)', new \DateTimeImmutable('2030-02-01'));

        self::assertSame('Forêt (nouveau)', $item->label());
        self::assertEquals(new \DateTimeImmutable('2030-02-01'), $item->seenAt());
    }

    /**
     * @return list<array{Ulid, ?string, int}>
     */
    private static function components(ExternalItem $item): array
    {
        return array_map(static fn (ExternalItemComponent $component): array => [$component->productId(), $component->variant(), $component->quantity()], $item->components());
    }
}
