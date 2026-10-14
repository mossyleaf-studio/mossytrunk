<?php

declare(strict_types=1);

namespace App\Application\Discount\CreateDiscountRule;

use App\Application\Discount\DiscountRuleDefinition;
use App\Application\Discount\DiscountRuleParts;
use App\Application\Transaction;
use App\Application\WorkspaceContext;
use App\Domain\Discount\DiscountRule;
use App\Domain\Discount\DiscountRuleRepository;
use Symfony\Component\Uid\Ulid;

final readonly class CreateDiscountRuleHandler
{
    public function __construct(
        private DiscountRuleRepository $rules,
        private DiscountRuleParts $parts,
        private Transaction $transaction,
        private WorkspaceContext $workspace,
    ) {
    }

    public function __invoke(DiscountRuleDefinition $definition): Ulid
    {
        $rule = DiscountRule::create(
            $this->workspace->current(),
            $definition->name,
            $this->parts->conditions($definition),
            $this->parts->action($definition),
            $this->parts->validity($definition),
            $definition->manual,
        );

        $this->rules->add($rule);
        $this->transaction->commit();

        return $rule->id();
    }
}
