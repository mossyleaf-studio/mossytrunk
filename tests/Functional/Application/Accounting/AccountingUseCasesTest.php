<?php

declare(strict_types=1);

namespace App\Tests\Functional\Application\Accounting;

use App\Application\Accounting\ChoosePeriodicity\ChoosePeriodicityHandler;
use App\Application\Accounting\DeclarePeriod\DeclarePeriodHandler;
use App\Application\Accounting\ExportOrders\ExportOrdersHandler;
use App\Application\Accounting\GetUrssafOverview\DeclarationPeriodView;
use App\Application\Accounting\GetUrssafOverview\GetUrssafOverviewHandler;
use App\Application\Accounting\WithdrawDeclaration\WithdrawDeclarationHandler;
use App\Application\Event\ScheduleEvent\ScheduleEvent;
use App\Application\Event\ScheduleEvent\ScheduleEventHandler;
use App\Application\Order\PlaceOrder\PlaceOrder;
use App\Application\Order\PlaceOrder\PlaceOrderHandler;
use App\Application\Order\RequestedLine;
use App\Domain\Accounting\DeclarationPeriodicity;
use App\Tests\Support\ActsAsUser;
use App\Tests\Support\CreatesProducts;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Translation\LocaleSwitcher;

final class AccountingUseCasesTest extends KernelTestCase
{
    use ActsAsUser;
    use CreatesProducts;
    private string $sticker;

    protected function setUp(): void
    {
        self::actAsMemberOf();
        self::getContainer()->get(ScheduleEventHandler::class)(new ScheduleEvent('Japan Expo', 'Villepinte', new \DateTimeImmutable('2026-07-09'), new \DateTimeImmutable('2026-07-12')));
        self::getContainer()->get(ScheduleEventHandler::class)(new ScheduleEvent('Salon de printemps', 'Lyon', new \DateTimeImmutable('2026-05-02'), new \DateTimeImmutable('2026-05-03')));
        $this->sticker = self::createProduct('Sticker', 400, 100);
    }

    public function testMonthlyTurnoverToDeclareAndItsStatus(): void
    {
        $this->place('2026-07-10 15:00', 3);
        $this->place('2026-05-02 11:00', 1);

        $overview = self::getContainer()->get(GetUrssafOverviewHandler::class)(2026);

        $july = $this->period($overview->periods, '2026-07');
        self::assertSame([1_200, 1, 154, 'late', '2026-08-31'], [$july->turnover, $july->orderCount, $july->contribution, $july->status, $july->deadline]);
        self::assertSame(['none' => 1_200], $july->payments);
        self::assertSame(1_600, $overview->yearTurnover);
        self::assertSame('inactive', $this->period($overview->periods, '2026-04')->status);
        self::assertSame(self::monthsFrom('2026-05'), array_column($overview->pending, 'key'));
    }

    public function testADeclarationKeepsTheDeclaredAmountAndFlagsLaterChanges(): void
    {
        $this->place('2026-07-10 15:00', 3);
        $declarations = self::getContainer()->get(DeclarePeriodHandler::class);

        $declarations('2026-07');
        self::assertSame('declared', $this->period(self::getContainer()->get(GetUrssafOverviewHandler::class)(2026)->periods, '2026-07')->status);

        $this->place('2026-07-11 10:00', 1);
        $july = $this->period(self::getContainer()->get(GetUrssafOverviewHandler::class)(2026)->periods, '2026-07');
        self::assertSame(['changed', 1_200, 1_600], [$july->status, $july->declaredTurnover, $july->turnover]);

        $declarations('2026-07');
        self::assertSame('declared', $this->period(self::getContainer()->get(GetUrssafOverviewHandler::class)(2026)->periods, '2026-07')->status);

        self::getContainer()->get(WithdrawDeclarationHandler::class)('2026-07');
        self::assertSame('late', $this->period(self::getContainer()->get(GetUrssafOverviewHandler::class)(2026)->periods, '2026-07')->status);
    }

    public function testQuarterlyDeclarations(): void
    {
        $this->place('2026-05-02 11:00', 2);
        self::getContainer()->get(ChoosePeriodicityHandler::class)(DeclarationPeriodicity::Quarterly);

        $overview = self::getContainer()->get(GetUrssafOverviewHandler::class)(2026);

        self::assertSame('quarterly', $overview->periodicity);
        self::assertSame(['2026-T1', '2026-T2', '2026-T3', '2026-T4'], array_column($overview->periods, 'key'));
        self::assertSame(800, $this->period($overview->periods, '2026-T2')->turnover);
    }

    public function testCsvExportOfTheOrdersOfAPeriod(): void
    {
        $this->place('2026-07-10 15:00', 3);
        $this->place('2026-05-02 11:00', 1);
        self::getContainer()->get(LocaleSwitcher::class)->setLocale('fr');

        $csv = self::getContainer()->get(ExportOrdersHandler::class)('2026-07-01', '2026-07-31');

        self::assertSame(1, $csv->orderCount);
        self::assertSame('commandes-2026-07-01-au-2026-07-31.csv', $csv->filename);
        $lines = explode("\r\n", trim($csv->content));
        self::assertStringStartsWith("\u{FEFF}Référence;Réf. externes;Date;Heure;Source;Événement", $lines[0]);
        self::assertMatchesRegularExpression('/^CMD-20260710-\w{6};;10\/07\/2026;15:00;Saisie;Japan Expo;;3;3 × Sticker;12,00;0,00;0,00;12,00;3,00;0,00;0,00;9,00$/', $lines[1]);
    }

    public function testCsvExportFollowsTheLanguage(): void
    {
        $this->place('2026-07-10 15:00', 1);
        self::getContainer()->get(LocaleSwitcher::class)->setLocale('en');

        $csv = self::getContainer()->get(ExportOrdersHandler::class)('2026-07-01', '2026-07-31');

        self::assertSame('orders-2026-07-01-to-2026-07-31.csv', $csv->filename);
        $lines = explode("\r\n", trim($csv->content));
        self::assertStringStartsWith("\u{FEFF}Reference;External references;Date;Time;Source;Event", $lines[0]);
        self::assertStringContainsString(';Manual entry;Japan Expo;', $lines[1]);
    }

    /**
     * @param list<DeclarationPeriodView> $periods
     */
    private function period(array $periods, string $key): DeclarationPeriodView
    {
        foreach ($periods as $period) {
            if ($period->key === $key) {
                return $period;
            }
        }

        self::fail("No period $key");
    }

    private function place(string $placedAt, int $quantity): void
    {
        self::getContainer()->get(PlaceOrderHandler::class)(new PlaceOrder(new \DateTimeImmutable($placedAt, new \DateTimeZone('Europe/Paris')), [new RequestedLine($this->sticker, null, $quantity)]));
    }

    /**
     * @return list<string>
     */
    private static function monthsFrom(string $first): array
    {
        $months = [];
        $currentMonth = new \DateTimeImmutable('midnight first day of this month', new \DateTimeZone('Europe/Paris'));
        for ($month = new \DateTimeImmutable($first.'-01', new \DateTimeZone('Europe/Paris')); $month < $currentMonth; $month = $month->modify('+1 month')) {
            $months[] = $month->format('Y-m');
        }

        return $months;
    }
}
