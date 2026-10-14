<?php

declare(strict_types=1);

namespace App\Application\Discount;

final readonly class DiscountRuleDefinition
{
    /**
     * @param list<ConditionDefinition> $conditions
     */
    public function __construct(
        public string $name,
        public array $conditions,
        public string $actionKind,
        public int $actionValue,
        public ?string $startsOn = null,
        public ?string $endsOn = null,
        public bool $manual = false,
    ) {
    }
}
