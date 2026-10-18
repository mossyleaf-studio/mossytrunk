<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Doctrine\Reporting;

use App\Application\Integration\Connectors;
use App\Application\Order\ListOrders\OrderSummaries;
use App\Application\Order\ListOrders\OrderSummaryView;
use App\Application\WorkspaceContext;
use App\Domain\Shared\BusinessTime;
use Doctrine\DBAL\Connection;
use Symfony\Component\Uid\Ulid;

final readonly class DoctrineOrderSummaries implements OrderSummaries
{
    public function __construct(
        private Connection $connection,
        private WorkspaceContext $workspace,
        private Connectors $connectors,
    ) {
    }

    public function list(?Ulid $eventId = null): array
    {
        $rows = $this->connection->fetchAllAssociative(
            <<<'SQL'
                SELECT o.id, o.reference, o.placed_at, o.refunded_at, o.source, o.payment_method, o.note,
                    o.event_id, e.name AS event_name, o.channel_id, c.name AS channel_name,
                    o.subtotal_cents, o.discount_total_cents, o.total_cents,
                    o.total_cents - o.cost_of_goods_cents - o.supplies_cost_cents - o.channel_costs_cents AS profit,
                    l.items, l.unidentified, l.unknown_costs, s.sale_references
                FROM "order" o
                LEFT JOIN event e ON e.id = o.event_id
                LEFT JOIN sales_channel c ON c.id = o.channel_id
                LEFT JOIN LATERAL (
                    SELECT SUM(quantity) AS items,
                        COUNT(*) FILTER (WHERE product_id IS NULL) AS unidentified,
                        COUNT(*) FILTER (WHERE product_id IS NOT NULL AND cost_cents = 0) AS unknown_costs
                    FROM order_line WHERE order_id = o.id
                ) l ON TRUE
                LEFT JOIN LATERAL (
                    SELECT JSON_AGG(reference ORDER BY id) AS sale_references FROM order_imported_sale WHERE order_id = o.id
                ) s ON TRUE
                WHERE o.workspace_id = :workspace AND (CAST(:event AS uuid) IS NULL OR o.event_id = CAST(:event AS uuid))
                ORDER BY o.placed_at DESC
                SQL,
            ['workspace' => $this->workspace->current()->id()->toRfc4122(), 'event' => $eventId?->toRfc4122()],
        );

        return array_map($this->view(...), $rows);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function view(array $row): OrderSummaryView
    {
        $source = SqlValue::string($row['source']);
        $references = json_decode(SqlValue::string($row['sale_references']) ?: '[]', true);

        return new OrderSummaryView(
            (string) SqlValue::ulid($row['id']),
            SqlValue::string($row['reference']),
            self::moment($row['placed_at']) ?? '',
            null === $row['event_id'] ? null : (string) SqlValue::ulid($row['event_id']),
            SqlValue::nullableString($row['event_name']),
            SqlValue::int($row['items']),
            SqlValue::int($row['subtotal_cents']),
            SqlValue::int($row['discount_total_cents']),
            SqlValue::int($row['total_cents']),
            $source,
            $this->connectors->labelOf($source),
            SqlValue::nullableString($row['payment_method']),
            \is_array($references) ? array_values(array_map(SqlValue::string(...), $references)) : [],
            self::moment($row['refunded_at']),
            SqlValue::int($row['unidentified']),
            null === $row['channel_id'] ? null : (string) SqlValue::ulid($row['channel_id']),
            SqlValue::nullableString($row['channel_name']),
            SqlValue::int($row['profit']),
            SqlValue::int($row['unknown_costs']),
            SqlValue::nullableString($row['note']),
        );
    }

    private static function moment(mixed $value): ?string
    {
        return \is_string($value) ? BusinessTime::atom(new \DateTimeImmutable($value)) : null;
    }
}
