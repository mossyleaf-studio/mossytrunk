<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Event;

use App\Domain\Event\Event;
use App\Domain\Event\EventTiming;
use App\Domain\Event\Exception\InvalidEvent;
use App\Domain\Event\Exception\SharingEndsBeforeEvent;
use App\Domain\Event\Exception\TooFewSharingEvents;
use App\Domain\Event\Exception\UnknownDonation;
use App\Domain\Event\ExpenseShares;
use App\Domain\Event\ExpenseSpread;
use App\Domain\Shared\DateRange;
use App\Domain\Shared\Exception\InvalidMoney;
use App\Domain\Shared\Exception\NonPositiveAmount;
use App\Domain\Shared\Money;
use App\Tests\Support\TestWorkspace;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Ulid;

final class EventTest extends TestCase
{
    public function testNameAndLocationAreRequired(): void
    {
        $this->expectException(InvalidEvent::class);

        Event::schedule(TestWorkspace::get(), 'Japan Expo', '  ', self::period());
    }

    public function testCoversDaysOfItsPeriod(): void
    {
        $event = Event::schedule(TestWorkspace::get(), 'Japan Expo', 'Villepinte', self::period());

        self::assertTrue($event->covers(new \DateTimeImmutable('2026-07-10 18:00', new \DateTimeZone('Europe/Paris'))));
        self::assertFalse($event->covers(new \DateTimeImmutable('2026-07-13 09:00', new \DateTimeZone('Europe/Paris'))));
    }

    public function testTimingRelativeToToday(): void
    {
        $event = Event::schedule(TestWorkspace::get(), 'Japan Expo', 'Villepinte', self::period()); // 9 → 12 July 2026
        $paris = new \DateTimeZone('Europe/Paris');

        self::assertSame(EventTiming::Upcoming, $event->timingOn(new \DateTimeImmutable('2026-07-08 23:59', $paris)));
        self::assertSame(EventTiming::Ongoing, $event->timingOn(new \DateTimeImmutable('2026-07-09 00:00', $paris)));
        self::assertSame(EventTiming::Ongoing, $event->timingOn(new \DateTimeImmutable('2026-07-12 23:59', $paris)));
        self::assertSame(EventTiming::Past, $event->timingOn(new \DateTimeImmutable('2026-07-13 00:00', $paris)));
        self::assertSame(EventTiming::Past, $event->timingOn(new \DateTimeImmutable('2026-07-12T22:30:00+00:00')));
    }

    public function testExpensesAreSummedAndRemovable(): void
    {
        $event = Event::schedule(TestWorkspace::get(), 'Japan Expo', 'Villepinte', self::period());
        $stand = $event->addExpense('Stand', Money::cents(30_000));
        $event->addExpense('Train', Money::cents(8_950));

        self::assertSame(38_950, ExpenseShares::among([$event])->totalOf($event)->amount());

        $event->removeExpense($stand->id());
        self::assertSame(8_950, ExpenseShares::among([$event])->totalOf($event)->amount());
        self::assertCount(1, $event->expenses());
    }

    public function testDonationsAreSummedAndRemovable(): void
    {
        $event = Event::schedule(TestWorkspace::get(), 'Japan Expo', 'Villepinte', self::period());
        $box = $event->receiveDonation('Boîte à dons', Money::cents(2_500));
        $event->receiveDonation('  ', Money::cents(500));

        self::assertSame(3_000, $event->donationsTotal()->amount());
        self::assertNull($event->donations()[1]->label());

        $event->removeDonation($box->id());
        self::assertSame(500, $event->donationsTotal()->amount());

        $this->expectException(UnknownDonation::class);
        $event->removeDonation($box->id());
    }

    public function testADonationIsPositive(): void
    {
        $this->expectException(NonPositiveAmount::class);

        Event::schedule(TestWorkspace::get(), 'Japan Expo', 'Villepinte', self::period())->receiveDonation(null, Money::zero());
    }

    public function testExpenseCanBeRevisedWithTheSameRules(): void
    {
        $event = Event::schedule(TestWorkspace::get(), 'Japan Expo', 'Villepinte', self::period());
        $stand = $event->addExpense('Stand', Money::cents(30_000));

        $event->reviseExpense($stand->id(), 'Stand (angle)', Money::cents(32_000));
        self::assertSame('Stand (angle)', $event->expenses()[0]->label());
        self::assertSame(32_000, ExpenseShares::among([$event])->totalOf($event)->amount());

        $this->expectException(InvalidMoney::class);
        $event->reviseExpense($stand->id(), 'Stand', Money::zero());
    }

    public function testASharedExpenseIsSplitBetweenItsEventAndTheFollowingOnes(): void
    {
        $first = Event::schedule(TestWorkspace::get(), 'Japan Expo', 'Villepinte', self::period());
        $before = Event::schedule(TestWorkspace::get(), 'Marché', 'Lyon', DateRange::fromDates(new \DateTimeImmutable('2026-06-01'), new \DateTimeImmutable('2026-06-01')));
        $first->addExpense('Nappe', Money::cents(10_000), ExpenseSpread::of(3, null));
        self::assertSame(10_000, ExpenseShares::among([$first, $before])->totalOf($first)->amount());

        $second = Event::schedule(TestWorkspace::get(), 'Salon', 'Lyon', DateRange::fromDates(new \DateTimeImmutable('2026-08-01'), new \DateTimeImmutable('2026-08-01')));
        $third = Event::schedule(TestWorkspace::get(), 'Salon', 'Paris', DateRange::fromDates(new \DateTimeImmutable('2026-09-01'), new \DateTimeImmutable('2026-09-01')));
        $fourth = Event::schedule(TestWorkspace::get(), 'Salon', 'Nantes', DateRange::fromDates(new \DateTimeImmutable('2026-10-01'), new \DateTimeImmutable('2026-10-01')));
        $shares = ExpenseShares::among([$fourth, $third, $second, $first, $before]);

        self::assertSame([3_334, 3_333, 3_333, 0, 0], array_map(static fn (Event $event): int => $shares->totalOf($event)->amount(), [$first, $second, $third, $fourth, $before]));
        self::assertSame(3, $shares->of($second)[0]->sharedBy);
    }

    public function testASharedExpenseStopsAtItsEndDate(): void
    {
        $first = Event::schedule(TestWorkspace::get(), 'Japan Expo', 'Villepinte', self::period());
        $second = Event::schedule(TestWorkspace::get(), 'Salon', 'Lyon', DateRange::fromDates(new \DateTimeImmutable('2026-12-31'), new \DateTimeImmutable('2027-01-01')));
        $later = Event::schedule(TestWorkspace::get(), 'Salon', 'Paris', DateRange::fromDates(new \DateTimeImmutable('2027-01-02'), new \DateTimeImmutable('2027-01-02')));
        $first->addExpense('Nappe', Money::cents(5_000), ExpenseSpread::of(null, new \DateTimeImmutable('2026-12-31')));
        $shares = ExpenseShares::among([$first, $second, $later]);

        self::assertSame([2_500, 2_500, 0], array_map(static fn (Event $event): int => $shares->totalOf($event)->amount(), [$first, $second, $later]));
    }

    public function testASharedExpenseCountsForAtLeastTwoEvents(): void
    {
        $this->expectException(TooFewSharingEvents::class);
        ExpenseSpread::of(1, null);
    }

    public function testASharedExpenseEndsAfterItsEventStarts(): void
    {
        $event = Event::schedule(TestWorkspace::get(), 'Japan Expo', 'Villepinte', self::period());

        $this->expectException(SharingEndsBeforeEvent::class);
        $event->addExpense('Nappe', Money::cents(5_000), ExpenseSpread::of(null, new \DateTimeImmutable('2026-07-08')));
    }

    public function testRemovingUnknownExpenseFails(): void
    {
        $event = Event::schedule(TestWorkspace::get(), 'Japan Expo', 'Villepinte', self::period());

        $this->expectException(InvalidEvent::class);
        $event->removeExpense(new Ulid());
    }

    public function testExpenseAmountMustBePositive(): void
    {
        $event = Event::schedule(TestWorkspace::get(), 'Japan Expo', 'Villepinte', self::period());

        $this->expectException(InvalidMoney::class);
        $event->addExpense('Stand', Money::zero());
    }

    public function testExpenseLabelIsRequired(): void
    {
        $event = Event::schedule(TestWorkspace::get(), 'Japan Expo', 'Villepinte', self::period());

        $this->expectException(InvalidEvent::class);
        $event->addExpense(' ', Money::cents(100));
    }

    private static function period(): DateRange
    {
        return DateRange::fromDates(new \DateTimeImmutable('2026-07-09'), new \DateTimeImmutable('2026-07-12'));
    }
}
