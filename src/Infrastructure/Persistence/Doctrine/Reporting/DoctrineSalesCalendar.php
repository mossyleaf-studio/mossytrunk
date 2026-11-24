<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Doctrine\Reporting;

use App\Application\Reporting\SalesCalendar;
use App\Application\WorkspaceContext;
use App\Domain\Reporting\Analysis\EventUnits;
use App\Domain\Reporting\Analysis\SellingDay;
use App\Domain\Reporting\Analysis\SoldEvent;
use App\Domain\Shared\BusinessTime;
use App\Domain\Shared\DateRange;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Types\Types;
use Symfony\Component\Uid\Ulid;

final readonly class DoctrineSalesCalendar implements SalesCalendar
{
    public function __construct(
        private Connection $connection,
        private WorkspaceContext $workspace,
    ) {
    }

    public function days(DateRange $period): array
    {
        return array_map(self::sellingDay(...), $this->rows(
            <<<'SQL'
                SELECT to_char(o.placed_at AT TIME ZONE :timezone, 'YYYY-MM-DD') AS day, COUNT(*) AS orders,
                    o.event_id, e.name AS event_name, e.period_start, e.period_end
                FROM "order" o
                LEFT JOIN event e ON e.id = o.event_id
                WHERE o.workspace_id = :workspace AND o.refunded_at IS NULL AND o.placed_at >= :from AND o.placed_at < :until
                GROUP BY day, o.event_id, e.name, e.period_start, e.period_end
                ORDER BY day, e.period_start NULLS LAST
                SQL,
            $period,
        ));
    }

    public function unitsByEvent(DateRange $period): array
    {
        return array_map(self::eventUnits(...), $this->rows(
            <<<'SQL'
                SELECT l.product_id, o.event_id, SUM(l.quantity) AS units,
                    to_char(MIN(o.placed_at) AT TIME ZONE :timezone, 'YYYY-MM-DD') AS first_sale,
                    to_char(MAX(o.placed_at) AT TIME ZONE :timezone, 'YYYY-MM-DD') AS last_sale
                FROM order_line l
                JOIN "order" o ON o.id = l.order_id
                WHERE o.workspace_id = :workspace AND o.refunded_at IS NULL AND l.product_id IS NOT NULL
                    AND o.placed_at >= :from AND o.placed_at < :until
                GROUP BY l.product_id, o.event_id
                SQL,
            $period,
        ));
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function rows(string $sql, DateRange $period): array
    {
        [$from, $until] = SalePeriodBounds::of($period);

        return $this->connection->fetchAllAssociative(
            $sql,
            ['workspace' => $this->workspace->current()->id()->toRfc4122(), 'timezone' => BusinessTime::ZONE, 'from' => $from, 'until' => $until],
            ['from' => Types::DATETIMETZ_IMMUTABLE, 'until' => Types::DATETIMETZ_IMMUTABLE],
        );
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function sellingDay(array $row): SellingDay
    {
        $event = SqlValue::ulid($row['event_id']);

        return new SellingDay(
            SqlValue::string($row['day']),
            SqlValue::int($row['orders']),
            null === $event ? null : new SoldEvent($event, SqlValue::string($row['event_name']), SqlValue::string($row['period_start']), SqlValue::string($row['period_end'])),
        );
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function eventUnits(array $row): EventUnits
    {
        return new EventUnits(
            Ulid::fromString(SqlValue::string($row['product_id'])),
            SqlValue::ulid($row['event_id']),
            SqlValue::int($row['units']),
            SqlValue::string($row['first_sale']),
            SqlValue::string($row['last_sale']),
        );
    }
}
