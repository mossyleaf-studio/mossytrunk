<?php

declare(strict_types=1);

namespace App\Application\Reporting;

use App\Domain\Reporting\SalesTotals;
use App\Domain\Shared\Money;
use Symfony\Component\Uid\Ulid;

interface SalesLedger
{
    /**
     * @return array<string, SalesTotals>
     */
    public function totalsByEvent(): array;

    public function totalsOfEvent(Ulid $eventId): SalesTotals;

    /**
     * @return array<string, SalesTotals>
     */
    public function totalsByMonth(): array;

    /**
     * @return array<string, array<string, Money>>
     */
    public function turnoverByMonthAndPayment(): array;

    public function firstSaleAt(): ?\DateTimeImmutable;

    public function lastSaleAt(): ?\DateTimeImmutable;
}
