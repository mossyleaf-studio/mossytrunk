<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Application\Discount\ConditionDefinition;
use App\Application\Discount\DiscountRuleDefinition;
use App\Application\Discount\ListDiscountRules\DiscountRuleView;
use App\Application\Discount\TargetDefinition;

final class DiscountRules
{
    public static function fixedPrice(string $name, int $cents, ConditionDefinition ...$conditions): DiscountRuleDefinition
    {
        return new DiscountRuleDefinition($name, array_values($conditions), 'fixedPrice', $cents);
    }

    public static function product(string $productId, int $quantity, ?string $variant = null): ConditionDefinition
    {
        return new ConditionDefinition($quantity, [self::productTarget($productId, $variant)]);
    }

    public static function type(string $typeId, int $quantity, ?string $variant = null): ConditionDefinition
    {
        return new ConditionDefinition($quantity, [self::typeTarget($typeId, $variant)]);
    }

    public static function anyOf(int $quantity, TargetDefinition ...$targets): ConditionDefinition
    {
        return new ConditionDefinition($quantity, array_values($targets));
    }

    public static function productTarget(string $productId, ?string $variant = null): TargetDefinition
    {
        return new TargetDefinition(TargetDefinition::PRODUCT, $productId, $variant);
    }

    public static function typeTarget(string $typeId, ?string $variant = null, ?string $collectionId = null): TargetDefinition
    {
        return new TargetDefinition(TargetDefinition::TYPE, $typeId, $variant, $collectionId);
    }

    /**
     * @return list<string>
     */
    public static function names(DiscountRuleView $rule): array
    {
        return array_map(static fn (array $condition): string => implode(' / ', array_column($condition['targets'], 'name')), $rule->conditions);
    }
}
