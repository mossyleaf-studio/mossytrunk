<?php

declare(strict_types=1);

namespace App\Tests\Functional\Application\Order;

use App\Application\Discount\CreateDiscountRule\CreateDiscountRuleHandler;
use App\Application\Event\ScheduleEvent\ScheduleEvent;
use App\Application\Event\ScheduleEvent\ScheduleEventHandler;
use App\Application\Order\CoverRuleDiscounts\CoverRuleDiscountsHandler;
use App\Domain\Discount\AppliedDiscount;
use App\Domain\Event\EventRepository;
use App\Domain\Identity\User;
use App\Domain\Order\Order;
use App\Domain\Order\OrderedItem;
use App\Domain\Order\OrderRepository;
use App\Domain\Product\ProductRepository;
use App\Domain\Shared\BusinessTime;
use App\Domain\Shared\Money;
use App\Tests\Support\ActsAsUser;
use App\Tests\Support\CreatesProducts;
use App\Tests\Support\DiscountRules;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Uid\Ulid;

final class CoverRuleDiscountsTest extends KernelTestCase
{
    use ActsAsUser;
    use CreatesProducts;

    private User $member;
    private Ulid $orderId;

    protected function setUp(): void
    {
        $this->member = self::actAsMemberOf();
        $eventId = self::getContainer()->get(ScheduleEventHandler::class)(
            new ScheduleEvent('Geek Days', 'Lille', new \DateTimeImmutable('2026-11-21'), new \DateTimeImmutable('2026-11-22')),
        );
        $print = Ulid::fromString(self::createProduct('Print Fox', 500, 51));
        $sticker = Ulid::fromString(self::createProduct('Sticker Fox', 300, 18));
        $rule = self::getContainer()->get(CreateDiscountRuleHandler::class)(DiscountRules::fixedPrice('3 Stickers', 800, DiscountRules::product((string) $sticker, 3)));

        $products = self::getContainer()->get(ProductRepository::class);
        $order = Order::place('CMD-1', self::getContainer()->get(EventRepository::class)->get($eventId), BusinessTime::at('2026-11-21 15:00'), [
            new OrderedItem($products->get($print)->sellable(null), 1),
            new OrderedItem($products->get($sticker)->sellable(null), 3),
        ], [new AppliedDiscount('3 Stickers', Money::cents(100), $rule)]);
        self::getContainer()->get(OrderRepository::class)->add($order);
        self::getContainer()->get(EntityManagerInterface::class)->flush();
        $this->orderId = $order->id();
        self::assertSame([36, 64], $this->lineDiscounts());
    }

    public function testARuleDiscountRecordedWithoutItsUnitsIsSharedOnlyBetweenTheLinesItCovers(): void
    {
        self::assertSame(1, self::getContainer()->get(CoverRuleDiscountsHandler::class)());

        self::assertSame([0, 100], $this->lineDiscounts());
        self::assertSame(0, self::getContainer()->get(CoverRuleDiscountsHandler::class)());
    }

    public function testTheCommandCoversTheRuleDiscountsOfTheWorkspaceOfTheGivenUser(): void
    {
        self::getContainer()->get('security.token_storage')->setToken(null);
        $tester = new CommandTester((new Application(self::$kernel ?? self::bootKernel()))->find('app:orders:cover-rule-discounts'));

        self::assertSame(0, $tester->execute(['email' => $this->member->email()]));
        self::assertStringContainsString('1', $tester->getDisplay());
        self::actAs($this->member);
        self::assertSame([0, 100], $this->lineDiscounts());
    }

    /**
     * @return list<int>
     */
    private function lineDiscounts(): array
    {
        self::getContainer()->get(EntityManagerInterface::class)->clear();
        $lines = self::getContainer()->get(OrderRepository::class)->get($this->orderId)->lines();
        usort($lines, static fn ($a, $b): int => $a->productName() <=> $b->productName());

        return array_map(static fn ($line): int => $line->discount()->amount(), $lines);
    }
}
