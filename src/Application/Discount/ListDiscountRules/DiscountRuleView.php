<?php

declare(strict_types=1);

namespace App\Application\Discount\ListDiscountRules;

use App\Domain\Discount\ConditionTarget;
use App\Domain\Discount\DiscountCondition;
use App\Domain\Discount\DiscountRule;

final readonly class DiscountRuleView
{
    /**
     * @param list<array{quantity: int, targets: list<array{kind: string, id: string, name: string, variant: ?string, collectionId: ?string, collectionName: ?string}>}> $conditions
     * @param array{kind: string, value: int}                                                                                                                            $action
     */
    public function __construct(
        public string $id,
        public string $name,
        public string $status,
        public array $conditions,
        public array $action,
        public ?string $startsOn,
        public ?string $endsOn,
    ) {
    }

    public static function fromRule(DiscountRule $rule, \DateTimeImmutable $today): self
    {
        return new self(
            (string) $rule->id(),
            $rule->name(),
            $rule->statusOn($today)->value,
            array_map(static fn (DiscountCondition $condition): array => [
                'quantity' => $condition->quantity(),
                'targets' => array_map(static fn (ConditionTarget $target): array => [
                    'kind' => $target->kind(),
                    'id' => (string) $target->subjectId(),
                    'name' => $target->name(),
                    'variant' => $target->variant(),
                    'collectionId' => null === $target->collection() ? null : (string) $target->collection()->id(),
                    'collectionName' => $target->collection()?->name(),
                ], $condition->targets()),
            ], $rule->conditions()),
            ['kind' => $rule->action()->kind()->value, 'value' => $rule->action()->value()],
            $rule->validity()->start()?->format('Y-m-d'),
            $rule->validity()->end()?->format('Y-m-d'),
        );
    }
}
