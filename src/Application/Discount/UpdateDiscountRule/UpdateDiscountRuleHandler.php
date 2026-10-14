<?php

declare(strict_types=1);

namespace App\Application\Discount\UpdateDiscountRule;

use App\Application\Discount\DiscountRuleDefinition;
use App\Application\Discount\DiscountRuleParts;
use App\Application\Transaction;
use App\Domain\Discount\DiscountRuleRepository;
use Symfony\Component\Uid\Ulid;

final readonly class UpdateDiscountRuleHandler
{
    public function __construct(
        private DiscountRuleRepository $rules,
        private DiscountRuleParts $parts,
        private Transaction $transaction,
    ) {
    }

    public function __invoke(string $ruleId, DiscountRuleDefinition $definition): void
    {
        $this->rules->get(Ulid::fromString($ruleId))->redefine(
            $definition->name,
            $this->parts->conditions($definition),
            $this->parts->action($definition),
            $this->parts->validity($definition),
            $definition->manual,
        );

        $this->transaction->commit();
    }
}
