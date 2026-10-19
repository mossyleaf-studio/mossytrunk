<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Doctrine;

use App\Application\Product\Variants\VariantRelabelling;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Ulid;

final readonly class DoctrineVariantRelabelling implements VariantRelabelling
{
    private const array TABLES = ['stock_item', 'order_line', 'supplier_order_line', 'stock_check_line', 'external_item_component'];

    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function relabel(array $productIds, string $from, string $to): void
    {
        if ([] === $productIds) {
            return;
        }

        $connection = $this->entityManager->getConnection();
        foreach (self::TABLES as $table) {
            $connection->executeStatement(
                \sprintf('UPDATE %s SET variant = :to WHERE product_id IN (:products) AND LOWER(variant) = LOWER(:from)', $table),
                ['to' => $to, 'from' => $from, 'products' => array_map(static fn (Ulid $id): string => $id->toRfc4122(), $productIds)],
                ['products' => ArrayParameterType::STRING],
            );
        }
    }
}
