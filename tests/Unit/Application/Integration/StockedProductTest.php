<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\Integration;

use App\Application\Integration\StockedCollection;
use App\Application\Integration\StockedItem;
use App\Application\Integration\StockedProduct;
use App\Domain\Shared\Money;
use PHPUnit\Framework\TestCase;

final class StockedProductTest extends TestCase
{
    public function testAProductDescribedKeepsEverythingElseItCarriedItsCollectionIncluded(): void
    {
        $collection = new StockedCollection('01JD3V5M8Q00000000000000C1', 'Forêt de mousse');
        $product = new StockedProduct('01JD3V5M8Q0000000000000002', 'STK-001', 'Mousse', 'Sticker', 'Sticker Mousse', Money::cents(400), [new StockedItem(null, 'STK-001', 3)], collection: $collection);

        $described = $product->describedIn(['fr' => 'Un sticker.']);

        self::assertSame(['fr' => 'Un sticker.'], $described->descriptions);
        self::assertSame($collection, $described->collection);
        self::assertEquals(new StockedProduct($product->id, $product->reference, $product->name, $product->category, $product->displayName, $product->price, $product->items, ['fr' => 'Un sticker.'], $collection), $described);
    }
}
