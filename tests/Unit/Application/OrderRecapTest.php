<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application;

use App\Application\Event\GetEventReport\OrderRecap;
use App\Domain\Product\Product;
use App\Domain\Product\ProductType;
use App\Domain\Reporting\ProductSales;
use App\Domain\Shared\Money;
use App\Tests\Support\TestWorkspace;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Ulid;

final class OrderRecapTest extends TestCase
{
    public function testGroupsByTypeThenProductThenVariant(): void
    {
        $print = ProductType::create(TestWorkspace::get(), 'Print', 'PRI');
        $sticker = ProductType::create(TestWorkspace::get(), 'Sticker', 'STI');
        $foret = Product::create(TestWorkspace::get(), 'PRI-FORET', 'Forêt', Money::cents(1_500), variants: ['A4', 'A3'], type: $print);
        $riviere = Product::create(TestWorkspace::get(), 'PRI-RIVIERE', 'Rivière', Money::cents(1_500), variants: ['A4'], type: $print);
        $mousse = Product::create(TestWorkspace::get(), 'STI-MOUSSE', 'Mousse', Money::cents(400), type: $sticker);
        $deletedId = new Ulid();

        $groups = OrderRecap::group([
            self::sales($foret, 'A4', 3, 4_500),
            self::sales($foret, 'A3', 1, 1_500),
            self::sales($riviere, 'A4', 2, 3_000),
            self::sales($mousse, null, 5, 2_000, cost: 0),
            new ProductSales('Ancien produit', $deletedId, 'Ancien produit', null, 1, Money::cents(900), Money::cents(900), Money::cents(100), false, 0, Money::zero()),
        ], [
            (string) $foret->id() => $foret,
            (string) $riviere->id() => $riviere,
            (string) $mousse->id() => $mousse,
        ]);

        self::assertSame(['Print', 'Sticker', null], array_column($groups, 'type'));
        self::assertSame(6, $groups[0]['quantity']);
        self::assertSame(9_000, $groups[0]['sales']);
        self::assertSame(['Print Forêt', 'Print Rivière'], array_column($groups[0]['products'], 'name'));
        self::assertSame(['A4', 'A3'], array_column($groups[0]['products'][0]['variants'], 'variant'));
        self::assertSame(4, $groups[0]['products'][0]['quantity']);
        self::assertSame([], $groups[1]['products'][0]['variants'], 'unique product: no variant level');
        self::assertTrue($groups[1]['unknownCost']);
        self::assertSame('Ancien produit', $groups[2]['products'][0]['name']);
    }

    private static function sales(Product $product, ?string $variant, int $quantity, int $sales, int $cost = 100): ProductSales
    {
        return new ProductSales($product->displayName(), $product->id(), $product->displayName(), $variant, $quantity, Money::cents($sales), Money::cents($sales), Money::cents($cost * $quantity), 0 === $cost, 0, Money::zero());
    }
}
