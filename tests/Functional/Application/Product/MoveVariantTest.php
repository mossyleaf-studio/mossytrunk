<?php

declare(strict_types=1);

namespace App\Tests\Functional\Application\Product;

use App\Application\Discount\CreateDiscountRule\CreateDiscountRuleHandler;
use App\Application\Discount\ListDiscountRules\ListDiscountRulesHandler;
use App\Application\Event\ScheduleEvent\ScheduleEvent;
use App\Application\Event\ScheduleEvent\ScheduleEventHandler;
use App\Application\Integration\ConfigureConnection\AddConnectionHandler;
use App\Application\Integration\ImportSales\ImportSalesHandler;
use App\Application\Order\GetOrder\GetOrderHandler;
use App\Application\Order\ListOrders\ListOrdersHandler;
use App\Application\Order\PlaceOrder\PlaceOrder;
use App\Application\Order\PlaceOrder\PlaceOrderHandler;
use App\Application\Order\RequestedLine;
use App\Application\Product\ListProducts\ListProductsHandler;
use App\Application\Product\MoveVariant\MoveVariant;
use App\Application\Product\MoveVariant\MoveVariantHandler;
use App\Domain\Product\Exception\SoldWithoutVariant;
use App\Domain\Product\Exception\UnknownVariant;
use App\Domain\Product\Exception\VariantMovedOntoItself;
use App\Domain\Shared\Money;
use App\Infrastructure\Connector\SumUp\FakeSumUpGateway;
use App\Tests\Support\ActsAsUser;
use App\Tests\Support\CreatesProducts;
use App\Tests\Support\DiscountRules;
use App\Tests\Support\ExternalSales;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class MoveVariantTest extends KernelTestCase
{
    use ActsAsUser;
    use CreatesProducts;

    protected function setUp(): void
    {
        self::actAsMemberOf();
        self::getContainer()->get(ScheduleEventHandler::class)(new ScheduleEvent('Salon', 'Lyon', new \DateTimeImmutable('2030-03-14'), new \DateTimeImmutable('2030-03-15')));
    }

    public function testProductsSplitPerVariantBecomeOneProductWithVariants(): void
    {
        $lichen = $this->product('Mug Lichen', 1_200);
        $fougere = $this->product('Mug Fougère', 1_200);
        $order = $this->order([[$lichen, null, 2], [$fougere, null, 1]]);

        $mug = (string) $this->move(new MoveVariant($lichen, null, null, 'Mug', 'Lichen'));
        $this->move(new MoveVariant($fougere, null, $mug, null, 'Fougère'));

        $products = array_column($this->products(), null, 'name');
        self::assertSame(['Mug'], array_keys($products));
        self::assertSame(['Lichen', 'Fougère'], $products['Mug']->variants);
        self::assertSame(1_200, $products['Mug']->sellingPrice);
        $lines = self::getContainer()->get(GetOrderHandler::class)($order)->lines;
        self::assertEqualsCanonicalizing([['Mug — Lichen', 2], ['Mug — Fougère', 1]], array_map(static fn (array $line): array => [$line['label'], $line['quantity']], $lines));
        self::assertSame(3_600, self::getContainer()->get(GetOrderHandler::class)($order)->total, 'prices of past sales are kept');
    }

    public function testSalesOfAProductCreatedByASumUpImportTakeTheTargetPriceAndCostWhenMoved(): void
    {
        ExternalSales::connect(self::getContainer()->get(AddConnectionHandler::class), 'sumup', ['merchant_code' => 'MCODE', 'api_key' => 'sup_sk_test']);
        $bobcat = self::createProduct('Bobcat', 300, 80);
        self::getContainer()->get(FakeSumUpGateway::class)->willReturn([
            ExternalSales::sumUp('TX-BOB', new \DateTimeImmutable('2030-03-14T12:00:00Z'), Money::cents(450), [ExternalSales::line('Mossy Bobcat Print', Money::cents(250), 2)]),
        ]);
        self::getContainer()->get(ImportSalesHandler::class)('sumup');
        $created = array_column($this->products(), 'id', 'name')['Mossy Bobcat Print'];
        $order = self::getContainer()->get(ListOrdersHandler::class)()[0]->id;

        $this->move(new MoveVariant($created, null, $bobcat, null, null));

        $view = self::getContainer()->get(GetOrderHandler::class)($order);
        self::assertSame([['Bobcat', 2, 300]], array_map(static fn (array $line): array => [$line['label'], $line['quantity'], $line['unitPrice']], $view->lines));
        self::assertSame([450, 150, 160], [$view->total, $view->discounts[0]['amount'], $view->costOfGoods]);
    }

    public function testAVariantMovesToAnotherProductAndMergesWithItsSales(): void
    {
        $shirt = $this->product('T-shirt', 2_000, ['S', 'M']);
        $tee = $this->product('Tee', 2_000, ['M']);
        $order = $this->order([[$shirt, 'M', 1], [$tee, 'M', 2], [$shirt, 'S', 1]]);

        $this->move(new MoveVariant($shirt, 'M', $tee, null, 'M'));

        $variants = array_column($this->products(), 'variants', 'name');
        self::assertSame(['S'], $variants['T-shirt']);
        self::assertSame(['M'], $variants['Tee']);
        $lines = self::getContainer()->get(GetOrderHandler::class)($order)->lines;
        self::assertEqualsCanonicalizing([['Tee — M', 3], ['T-shirt — S', 1]], array_map(static fn (array $line): array => [$line['label'], $line['quantity']], $lines));
    }

    public function testMovingTheLastVariantRemovesTheProductAndMovesItsDiscountsOntoTheTargetVariant(): void
    {
        $old = $this->product('Vieux print', 1_500, ['A4']);
        $print = $this->product('Print', 1_500, ['A3']);
        self::getContainer()->get(CreateDiscountRuleHandler::class)(DiscountRules::fixedPrice('2 prints', 2_500, DiscountRules::product($old, 2)));

        $this->move(new MoveVariant($old, 'A4', $print, null, 'A4'));

        self::assertSame(['Print'], array_column($this->products(), 'name'));
        self::assertSame([['Print · A4', 'A4', 2]], array_map(
            static fn (array $condition): array => [$condition['targets'][0]['name'], $condition['targets'][0]['variant'], $condition['quantity']],
            self::getContainer()->get(ListDiscountRulesHandler::class)()[0]->conditions,
        ));
    }

    public function testATargetSoldWithoutVariantCannotGetAFirstOne(): void
    {
        $lichen = $this->product('Mug Lichen', 1_200);
        $mug = $this->product('Mug', 1_200);
        $this->order([[$mug, null, 1]]);

        $this->expectExceptionObject(new SoldWithoutVariant('Mug'));
        $this->move(new MoveVariant($lichen, null, $mug, null, 'Lichen'));
    }

    public function testAProductCannotMoveOntoItself(): void
    {
        $shirt = $this->product('T-shirt', 2_000, ['S', 'M']);

        $this->expectExceptionObject(new VariantMovedOntoItself());
        $this->move(new MoveVariant($shirt, 'M', $shirt, null, 'L'));
    }

    public function testTheMovedVariantMustExist(): void
    {
        $shirt = $this->product('T-shirt', 2_000, ['S']);

        $this->expectExceptionObject(new UnknownVariant('T-shirt', 'XL'));
        $this->move(new MoveVariant($shirt, 'XL', null, 'Tee', null));
    }

    /**
     * @param list<string> $variants
     */
    private function product(string $name, int $price, array $variants = []): string
    {
        return (string) self::createProduct($name, $price, 300, $variants);
    }

    /**
     * @param list<array{string, ?string, int}> $lines
     */
    private function order(array $lines): string
    {
        $order = self::getContainer()->get(PlaceOrderHandler::class)(new PlaceOrder(
            new \DateTimeImmutable('2030-03-14 12:00', new \DateTimeZone('Europe/Paris')),
            array_map(static fn (array $line): RequestedLine => new RequestedLine(...$line), $lines),
        ));

        return (string) $order->id();
    }

    private function move(MoveVariant $command): \Symfony\Component\Uid\Ulid
    {
        $target = self::getContainer()->get(MoveVariantHandler::class)($command);
        self::getContainer()->get('doctrine')->getManager()->clear();

        return $target;
    }

    /**
     * @return list<\App\Application\Product\ListProducts\ProductView>
     */
    private function products(): array
    {
        return self::getContainer()->get(ListProductsHandler::class)();
    }
}
