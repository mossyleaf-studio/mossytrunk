<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Doctrine\Reporting;

use App\Application\Reporting\ProductSalesLedger;
use App\Application\WorkspaceContext;
use App\Domain\Product\VariantLabel;
use App\Domain\Reporting\ProductSales;
use App\Domain\Shared\DateRange;
use App\Domain\Shared\Money;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Types\Types;
use Symfony\Component\Uid\Ulid;

final readonly class DoctrineProductSalesLedger implements ProductSalesLedger
{
    public function __construct(
        private Connection $connection,
        private WorkspaceContext $workspace,
    ) {
    }

    public function ofEvent(Ulid $eventId): array
    {
        return $this->sales('l.product_id, l.variant', 'l.variant', 'o.event_id = :event', ['event' => $eventId->toRfc4122()]);
    }

    public function within(DateRange $period): array
    {
        [$from, $until] = SalePeriodBounds::of($period);

        return $this->sales(
            'l.product_id',
            'NULL',
            'l.product_id IS NOT NULL AND o.placed_at >= :from AND o.placed_at < :until',
            ['from' => $from, 'until' => $until],
            ['from' => Types::DATETIMETZ_IMMUTABLE, 'until' => Types::DATETIMETZ_IMMUTABLE],
        );
    }

    public function ofProduct(Ulid $productId, ?DateRange $period = null): ?ProductSales
    {
        [$from, $until] = null === $period ? [null, null] : SalePeriodBounds::of($period);

        return $this->sales(
            'l.product_id',
            'NULL',
            'l.product_id = :product AND (CAST(:from AS timestamptz) IS NULL OR (o.placed_at >= :from AND o.placed_at < :until))',
            ['product' => $productId->toRfc4122(), 'from' => $from, 'until' => $until],
            ['from' => Types::DATETIMETZ_IMMUTABLE, 'until' => Types::DATETIMETZ_IMMUTABLE],
        )[0] ?? null;
    }

    /**
     * @param array<string, string|\DateTimeImmutable|null> $parameters
     * @param array<string, string>                         $types
     *
     * @return list<ProductSales>
     */
    private function sales(string $group, string $variant, string $condition, array $parameters, array $types = []): array
    {
        return array_map(self::productSales(...), $this->rows($group, $variant, $condition, $parameters, $types));
    }

    /**
     * @param array<string, string|\DateTimeImmutable|null> $parameters
     * @param array<string, string>                         $types
     *
     * @return list<array<string, mixed>>
     */
    private function rows(string $group, string $variant, string $condition, array $parameters, array $types = []): array
    {
        return $this->connection->fetchAllAssociative(
            <<<SQL
                SELECT l.product_id AS product_id, {$variant} AS variant,
                    (ARRAY_AGG(l.product_name ORDER BY o.placed_at DESC))[1] AS product_name,
                    SUM(l.quantity) AS quantity, SUM(l.unit_price_cents * l.quantity) AS gross,
                    SUM(l.unit_price_cents * l.quantity - l.discount_cents) AS sales,
                    SUM(l.cost_cents) AS cost, BOOL_OR(l.cost_cents = 0) AS unknown_cost,
                    SUM(CASE WHEN l.discount_cents > 0 THEN l.quantity ELSE 0 END) AS discounted_units,
                    SUM(CASE WHEN l.discount_cents > 0 THEN l.unit_price_cents * l.quantity ELSE 0 END) AS discounted_gross
                FROM order_line l
                JOIN "order" o ON o.id = l.order_id
                WHERE o.workspace_id = :workspace AND o.refunded_at IS NULL AND {$condition}
                GROUP BY {$group}
                SQL,
            ['workspace' => $this->workspace->current()->id()->toRfc4122(), ...$parameters],
            $types,
        );
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function productSales(array $row): ProductSales
    {
        $name = SqlValue::string($row['product_name']);
        $variant = SqlValue::nullableString($row['variant']);

        return new ProductSales(
            VariantLabel::display($name, $variant),
            SqlValue::ulid($row['product_id']),
            $name,
            $variant,
            SqlValue::int($row['quantity']),
            Money::cents(SqlValue::int($row['gross'])),
            Money::cents(SqlValue::int($row['sales'])),
            Money::cents(SqlValue::int($row['cost'])),
            SqlValue::bool($row['unknown_cost']),
            SqlValue::int($row['discounted_units']),
            Money::cents(SqlValue::int($row['discounted_gross'])),
        );
    }
}
