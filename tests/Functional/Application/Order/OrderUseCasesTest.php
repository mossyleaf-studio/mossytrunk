<?php

declare(strict_types=1);

namespace App\Tests\Functional\Application\Order;

use App\Application\Discount\CreateDiscountRule\CreateDiscountRuleHandler;
use App\Application\Event\ScheduleEvent\ScheduleEvent;
use App\Application\Event\ScheduleEvent\ScheduleEventHandler;
use App\Application\Event\UpdateEvent\UpdateEvent;
use App\Application\Event\UpdateEvent\UpdateEventHandler;
use App\Application\Order\AnnotateOrder\AnnotateOrderHandler;
use App\Application\Order\DeleteOrder\DeleteOrderHandler;
use App\Application\Order\GetOrder\GetOrderHandler;
use App\Application\Order\ListOrders\ListOrdersHandler;
use App\Application\Order\PlaceOrder\PlaceOrder;
use App\Application\Order\PlaceOrder\PlaceOrderHandler;
use App\Application\Order\PreviewOrder\PreviewOrderHandler;
use App\Application\Order\RequestedLine;
use App\Application\Product\RecordSellingPrice\RecordSellingPriceHandler;
use App\Domain\Event\Exception\InvalidEvent;
use App\Domain\Order\Exception\InvalidOrder;
use App\Domain\Order\Exception\NoEventOnOrderDate;
use App\Domain\Product\Exception\InvalidProduct;
use App\Domain\Shared\BusinessTime;
use App\Tests\Support\ActsAsUser;
use App\Tests\Support\CreatesProducts;
use App\Tests\Support\DiscountRules;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Ulid;
use Symfony\Contracts\Translation\TranslatorInterface;

final class OrderUseCasesTest extends KernelTestCase
{
    use ActsAsUser;
    use CreatesProducts;
    private string $eventId;
    private string $sticker;
    private string $tshirt;
    private string $rule;

    protected function setUp(): void
    {
        self::actAsMemberOf();
        $this->eventId = (string) self::getContainer()->get(ScheduleEventHandler::class)(
            new ScheduleEvent('Japan Expo', 'Villepinte', new \DateTimeImmutable('2026-07-09'), new \DateTimeImmutable('2026-07-12')),
        );
        $this->sticker = (string) self::createProduct('Sticker', 400, 80);
        $this->tshirt = (string) self::createProduct('T-shirt', 2_000, 900, ['S', 'M']);
        $this->rule = (string) self::getContainer()->get(CreateDiscountRuleHandler::class)(DiscountRules::fixedPrice('3 stickers pour 10 €', 1_000, DiscountRules::product($this->sticker, 3)));
    }

    public function testPlaceOrderLinksEventAndAppliesDiscounts(): void
    {
        $order = $this->place('2026-07-10 15:30', [new RequestedLine($this->sticker, null, 3), new RequestedLine($this->tshirt, 'M', 1)]);
        self::getContainer()->get('doctrine')->getManager()->clear();

        $view = self::getContainer()->get(GetOrderHandler::class)((string) $order->id());
        self::assertSame('Japan Expo', $view->event['name'] ?? null);
        self::assertSame('Marchés', $view->channelName, 'a manual order goes on the main market channel');
        self::assertSame(3_200, $view->subtotal);
        self::assertSame([['label' => '3 stickers pour 10 €', 'amount' => 200, 'ruleId' => $this->rule]], $view->discounts);
        self::assertSame(3_000, $view->total);
        self::assertSame(1_140, $view->costOfGoods);
        self::assertSame(1_860, $view->margin);
        self::assertSame('2026-07-10T15:30:00+02:00', $view->placedAt);
    }

    public function testOrderOutsideAnyEventIsRejected(): void
    {
        try {
            $this->place('2026-07-13 10:00', [new RequestedLine($this->sticker, null, 1)]);
            self::fail('An order outside any event must be rejected.');
        } catch (NoEventOnOrderDate $exception) {
            $message = self::getContainer()->get(TranslatorInterface::class)->trans($exception->getMessage(), $exception->parameters(), 'exceptions', 'fr');
            self::assertStringStartsWith('Aucun événement le 13/07/2026.', $message);
        }
    }

    public function testAnOrderCanBePlacedOutsideEventsWithAComment(): void
    {
        $order = self::getContainer()->get(PlaceOrderHandler::class)(new PlaceOrder(BusinessTime::at('2026-07-13 10:00'), [new RequestedLine($this->sticker, null, 1)], [], true, '  Cadeau pour Léa  '));
        $outsideEvent = self::getContainer()->get(PlaceOrderHandler::class)(new PlaceOrder(BusinessTime::at('2026-07-10 15:30'), [new RequestedLine($this->sticker, null, 1)], [], true));
        self::getContainer()->get('doctrine')->getManager()->clear();

        $view = self::getContainer()->get(GetOrderHandler::class)((string) $order->id());
        self::assertSame([null, null, 'manual', 'Cadeau pour Léa', 400], [$view->event, $view->channelName, $view->source, $view->note, $view->total]);
        self::assertNull(self::getContainer()->get(GetOrderHandler::class)((string) $outsideEvent->id())->event, 'outside events even on the day of an event');
        self::assertNull(self::getContainer()->get(PreviewOrderHandler::class)(BusinessTime::at('2026-07-10 15:30'), [new RequestedLine($this->sticker, null, 1)], [], true)->event);

        self::getContainer()->get(AnnotateOrderHandler::class)((string) $order->id(), 'Cadeau pour Paul');
        self::assertSame('Cadeau pour Paul', self::getContainer()->get(GetOrderHandler::class)((string) $order->id())->note);
        self::getContainer()->get(AnnotateOrderHandler::class)((string) $order->id(), ' ');
        self::assertNull(self::getContainer()->get(GetOrderHandler::class)((string) $order->id())->note);
    }

    public function testVariantIsRequiredForProductsWithVariants(): void
    {
        $this->expectException(InvalidProduct::class);

        $this->place('2026-07-10 15:30', [new RequestedLine($this->tshirt, null, 1)]);
    }

    public function testQuantityMustBePositive(): void
    {
        $this->expectException(InvalidOrder::class);

        $this->place('2026-07-10 15:30', [new RequestedLine($this->sticker, null, 0)]);
    }

    public function testAnOrderTakesThePriceOfItsDayAndItsDiscountsFollow(): void
    {
        self::getContainer()->get(RecordSellingPriceHandler::class)($this->sticker, 350, '2026-07-01');

        $preview = self::getContainer()->get(PreviewOrderHandler::class)(new \DateTimeImmutable('2026-07-10 15:30'), [new RequestedLine($this->sticker, null, 3)]);
        self::assertSame([1_050, 50, 1_000], [$preview->subtotal, $preview->discountTotal, $preview->total]);

        $order = $this->place('2026-07-10 15:30', [new RequestedLine($this->sticker, null, 1)]);
        self::assertSame(350, $order->lines()[0]->unitPrice()->amount());
    }

    public function testPreviewMatchesPlacedOrder(): void
    {
        $preview = self::getContainer()->get(PreviewOrderHandler::class)(
            new \DateTimeImmutable('2026-07-10 15:30'),
            [new RequestedLine($this->sticker, null, 7)],
        );

        self::assertSame('Japan Expo', $preview->event['name'] ?? null);
        self::assertSame(2_800, $preview->subtotal);
        self::assertSame([['label' => '3 stickers pour 10 € ×2', 'amount' => 400, 'ruleId' => $this->rule]], $preview->discounts);
        self::assertSame(2_400, $preview->total);

        $noEvent = self::getContainer()->get(PreviewOrderHandler::class)(new \DateTimeImmutable('2026-08-01 12:00'), [new RequestedLine($this->sticker, null, 1)]);
        self::assertNull($noEvent->event);
    }

    public function testListFilterAndDelete(): void
    {
        $order = $this->place('2026-07-10 15:30', [new RequestedLine($this->sticker, null, 1)]);
        $this->place('2026-07-11 11:00', [new RequestedLine($this->sticker, null, 2)]);

        $list = self::getContainer()->get(ListOrdersHandler::class);
        self::assertCount(2, $list($this->eventId));
        self::assertSame(800, $list()[0]->total, 'most recent first');
        self::assertSame([], $list((string) new Ulid()));

        self::getContainer()->get(DeleteOrderHandler::class)((string) $order->id());
        self::assertCount(1, $list());
    }

    public function testEventCannotBeRescheduledAwayFromItsOrders(): void
    {
        $this->place('2026-07-12 18:00', [new RequestedLine($this->sticker, null, 1)]);

        $this->expectException(InvalidEvent::class);
        self::getContainer()->get(UpdateEventHandler::class)(new UpdateEvent($this->eventId, 'Japan Expo', 'Villepinte', new \DateTimeImmutable('2026-07-09'), new \DateTimeImmutable('2026-07-11')));
    }

    /**
     * @param list<RequestedLine> $lines
     */
    private function place(string $localTime, array $lines): \App\Domain\Order\Order
    {
        return self::getContainer()->get(PlaceOrderHandler::class)(
            new PlaceOrder(new \DateTimeImmutable($localTime, new \DateTimeZone('Europe/Paris')), $lines),
        );
    }
}
