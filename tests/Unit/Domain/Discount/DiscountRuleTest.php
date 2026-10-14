<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Discount;

use App\Domain\Design\DesignCollection;
use App\Domain\Discount\ConditionSpec;
use App\Domain\Discount\DiscountAction;
use App\Domain\Discount\DiscountCondition;
use App\Domain\Discount\DiscountRule;
use App\Domain\Discount\DiscountStatus;
use App\Domain\Discount\Exception\ConditionWithoutTarget;
use App\Domain\Discount\Exception\DuplicateConditionTarget;
use App\Domain\Discount\Exception\InvalidDiscountRule;
use App\Domain\Discount\Exception\OnlyEligibleCollection;
use App\Domain\Discount\Exception\OnlyEligibleProduct;
use App\Domain\Discount\Exception\OnlyEligibleType;
use App\Domain\Discount\TargetSpec;
use App\Domain\Discount\ValidityPeriod;
use App\Domain\Product\Exception\UnknownTypeVariant;
use App\Domain\Product\Exception\UnknownVariant;
use App\Domain\Product\Product;
use App\Domain\Product\ProductType;
use App\Domain\Shared\Money;
use App\Tests\Support\DomainExceptions;
use App\Tests\Support\TestProductType;
use App\Tests\Support\TestWorkspace;
use PHPUnit\Framework\TestCase;

final class DiscountRuleTest extends TestCase
{
    private ProductType $print;
    private Product $sticker;
    private Product $holo;

    protected function setUp(): void
    {
        $this->print = ProductType::create(TestWorkspace::get(), 'Print', 'PRI');
        $this->sticker = Product::create(TestWorkspace::get(), 'STK', 'Sticker', Money::cents(400), TestProductType::get());
        $this->holo = Product::create(TestWorkspace::get(), 'HOLO', 'Holo', Money::cents(600), TestProductType::get());
    }

    public function testConditionsDescribeTheirTarget(): void
    {
        $rule = $this->rule([ConditionSpec::on(2, $this->print), ConditionSpec::on(1, $this->sticker)]);

        self::assertSame(
            [['type', 'Print', 2], ['product', 'Sticker', 1]],
            array_map(static fn (DiscountCondition $c): array => [$c->targets()[0]->kind(), $c->name(), $c->quantity()], $rule->conditions()),
        );
    }

    public function testAManualRuleHasNoConditionAndIsLeftAloneWhenTheCatalogueChanges(): void
    {
        $rule = DiscountRule::create(TestWorkspace::get(), 'Cadeau', [ConditionSpec::on(1, $this->sticker)], DiscountAction::percentOff(10_000), null, true);

        $rule->withdrawProduct($this->sticker);
        $rule->withdrawType($this->print);
        $rule->replaceProduct($this->sticker, null, $this->holo, null);
        $rule->withdrawCollection(DesignCollection::start(TestWorkspace::get(), 'Eevee'));

        self::assertTrue($rule->isManual());
        self::assertSame([], $rule->conditions());
        self::assertFalse($rule->appliesAutomaticallyOn(new \DateTimeImmutable('2026-07-10')));
    }

    public function testNeedsANameAndAtLeastOneCondition(): void
    {
        $this->expectException(InvalidDiscountRule::class);

        $this->rule([]);
    }

    public function testNameIsRequired(): void
    {
        $this->expectException(InvalidDiscountRule::class);

        DiscountRule::create(TestWorkspace::get(), '  ', [ConditionSpec::on(1, $this->sticker)], DiscountAction::amountOff(Money::cents(100)));
    }

    public function testConditionQuantityIsAtLeastOne(): void
    {
        $this->expectException(InvalidDiscountRule::class);

        $this->rule([ConditionSpec::on(0, $this->sticker)]);
    }

    public function testATargetAppearsInOneConditionOnly(): void
    {
        $this->expectExceptionObject(new DuplicateConditionTarget('Print'));

        $this->rule([ConditionSpec::on(1, $this->print), ConditionSpec::on(2, $this->print)]);
    }

    public function testValidityEndCannotPrecedeItsStart(): void
    {
        $this->expectException(InvalidDiscountRule::class);

        ValidityPeriod::between(new \DateTimeImmutable('2026-07-10'), new \DateTimeImmutable('2026-07-09'));
    }

    public function testAppliesWithinItsPeriod(): void
    {
        $rule = $this->summer(new \DateTimeImmutable('2026-07-01'), null);

        self::assertFalse($rule->appliesOn(new \DateTimeImmutable('2026-06-30 12:00')));
        self::assertTrue($rule->appliesOn(new \DateTimeImmutable('2027-01-01 12:00')));
    }

    public function testStatusFollowsTheDates(): void
    {
        $rule = $this->summer(new \DateTimeImmutable('2026-07-01'), new \DateTimeImmutable('2026-07-31'));

        self::assertSame(DiscountStatus::Upcoming, $rule->statusOn(new \DateTimeImmutable('2026-06-30 12:00')));
        self::assertSame(DiscountStatus::Running, $rule->statusOn(new \DateTimeImmutable('2026-07-31 23:00', new \DateTimeZone('Europe/Paris'))));
        self::assertSame(DiscountStatus::Expired, $rule->statusOn(new \DateTimeImmutable('2026-08-01 12:00')));
    }

    public function testStoppingARunningDiscountEndsItYesterday(): void
    {
        $rule = $this->summer(new \DateTimeImmutable('2026-07-01'), null);

        $rule->stopBefore(new \DateTimeImmutable('2026-09-29 12:00'));

        self::assertSame('2026-09-28', $rule->validity()->end()?->format('Y-m-d'));
        self::assertSame(DiscountStatus::Expired, $rule->statusOn(new \DateTimeImmutable('2026-09-29 12:00')));
    }

    public function testADiscountStartedTodayCannotBeStopped(): void
    {
        $rule = $this->summer(new \DateTimeImmutable('2026-09-29'), null);

        $this->expectException(InvalidDiscountRule::class);

        $rule->stopBefore(new \DateTimeImmutable('2026-09-29 12:00'));
    }

    public function testStartingAnUpcomingDiscountMovesItsStartToToday(): void
    {
        $rule = $this->summer(new \DateTimeImmutable('2026-12-01'), new \DateTimeImmutable('2026-12-31'));

        $rule->startOn(new \DateTimeImmutable('2026-09-29 12:00'));

        self::assertSame(['2026-09-29', '2026-12-31'], [$rule->validity()->start()?->format('Y-m-d'), $rule->validity()->end()?->format('Y-m-d')]);
    }

    public function testAnExpiredDiscountCannotBeStartedAgain(): void
    {
        $rule = $this->summer(new \DateTimeImmutable('2026-07-01'), new \DateTimeImmutable('2026-07-31'));

        $this->expectException(InvalidDiscountRule::class);

        $rule->startOn(new \DateTimeImmutable('2026-09-29 12:00'));
    }

    private function summer(?\DateTimeImmutable $start, ?\DateTimeImmutable $end): DiscountRule
    {
        return DiscountRule::create(TestWorkspace::get(), 'Été', [ConditionSpec::on(1, $this->sticker)], DiscountAction::amountOff(Money::cents(100)), ValidityPeriod::between($start, $end));
    }

    public function testAReplacedProductIsRetargetedOrMergedWithTheTarget(): void
    {
        $other = Product::create(TestWorkspace::get(), 'OTH', 'Autre', Money::cents(400), TestProductType::get());
        $retargeted = $this->rule([ConditionSpec::on(2, $this->sticker)]);
        $merged = $this->rule([ConditionSpec::on(2, $this->sticker), ConditionSpec::on(1, $this->holo)]);

        $retargeted->replaceProduct($this->sticker, null, $other, null);
        $merged->replaceProduct($this->sticker, null, $this->holo, null);

        self::assertSame([['Autre', 2]], $this->targets($retargeted));
        self::assertSame([['Holo', 3]], $this->targets($merged));
    }

    public function testAProductCanBeWithdrawnUnlessItIsTheOnlyCondition(): void
    {
        $both = $this->rule([ConditionSpec::on(1, $this->sticker), ConditionSpec::on(1, $this->holo)]);
        $both->withdrawProduct($this->holo);
        self::assertSame([['Sticker', 1]], $this->targets($both));

        $this->expectException(InvalidDiscountRule::class);
        $both->withdrawProduct($this->sticker);
    }

    public function testConditionsCanTargetAVariantOfATypeOrOfAProduct(): void
    {
        $forest = $this->print('Forêt', ['A4', 'A3']);

        $rule = $this->rule([ConditionSpec::on(1, $this->print, 'a3'), ConditionSpec::on(2, $forest, ' a4 '), ConditionSpec::on(1, $this->print)]);

        self::assertSame(
            [['type', 'Print · A3', 'A3', 1], ['product', 'Print Forêt · A4', 'A4', 2], ['type', 'Print', null, 1]],
            array_map(static fn (DiscountCondition $c): array => [$c->targets()[0]->kind(), $c->name(), $c->targets()[0]->variant(), $c->quantity()], $rule->conditions()),
        );
    }

    public function testConditionsFromTheMostToTheLeastSpecific(): void
    {
        $forest = $this->print('Forêt', ['A3']);

        $rule = $this->rule([ConditionSpec::on(1, $this->print), ConditionSpec::on(1, $this->print, 'A3'), ConditionSpec::on(1, $forest), ConditionSpec::on(1, $forest, 'A3')]);

        self::assertSame(
            [['Print Forêt · A3', 4], ['Print Forêt', 3], ['Print · A3', 1], ['Print', 0]],
            array_map(static fn (DiscountCondition $c): array => [$c->name(), $c->specificity()], $rule->conditionsMostSpecificFirst()),
        );
    }

    public function testAConditionVariantMustBeOneOfTheType(): void
    {
        $this->print('Forêt', ['A3']);

        DomainExceptions::assertThrown(new UnknownTypeVariant('Print', 'A5'), fn () => $this->rule([ConditionSpec::on(1, $this->print, 'A5')]));
    }

    public function testAConditionVariantMustBeOneOfTheProduct(): void
    {
        $this->print('Rivière', ['A3']);
        $forest = $this->print('Forêt', ['A4']);

        DomainExceptions::assertThrown(new UnknownVariant('Print Forêt', 'A3'), fn () => $this->rule([ConditionSpec::on(1, $forest, 'A3')]));
    }

    public function testATargetWithTheSameVariantAppearsInOneConditionOnly(): void
    {
        $forest = $this->print('Forêt', ['A4', 'A3']);
        $this->rule([ConditionSpec::on(1, $forest), ConditionSpec::on(1, $forest, 'A3'), ConditionSpec::on(1, $forest, 'A4'), ConditionSpec::on(1, $this->print, 'A3')]);

        DomainExceptions::assertThrown(new DuplicateConditionTarget('Print Forêt · A3'), fn () => $this->rule([ConditionSpec::on(1, $forest, 'A3'), ConditionSpec::on(2, $forest, 'a3')]));
        DomainExceptions::assertThrown(new DuplicateConditionTarget('Print · A3'), fn () => $this->rule([ConditionSpec::on(1, $this->print, 'A3'), ConditionSpec::on(2, $this->print, 'a3')]));
    }

    public function testConditionsOnAMovedVariantFollowItAndOthersFollowWhenTheTargetHasTheirVariant(): void
    {
        $old = $this->print('Vieux', ['A4', 'A3', 'A5']);
        $forest = $this->print('Forêt', ['a3', 'Carré']);
        $rule = $this->rule([ConditionSpec::on(1, $old), ConditionSpec::on(2, $old, 'A4'), ConditionSpec::on(3, $old, 'A3'), ConditionSpec::on(4, $old, 'A5')]);

        $rule->replaceProduct($old, 'A4', $forest, 'Carré');

        self::assertSame([['Print Forêt · Carré', 3], ['Print Forêt · A3', 3]], $this->targets($rule));
    }

    public function testAMovedConditionMergesWithTheSameConditionOnTheTarget(): void
    {
        $old = $this->print('Vieux', ['A4']);
        $forest = $this->print('Forêt', ['A4']);
        $rule = $this->rule([ConditionSpec::on(2, $old, 'A4'), ConditionSpec::on(1, $forest, 'A4')]);

        $rule->replaceProduct($old, 'A4', $forest, 'A4');

        self::assertSame([['Print Forêt · A4', 3]], $this->targets($rule));
    }

    public function testARuleLeftWithoutConditionAfterAMoveIsRefused(): void
    {
        $old = $this->print('Vieux', ['A4', 'A3']);
        $forest = $this->print('Forêt', ['A5']);
        $rule = $this->rule([ConditionSpec::on(1, $old, 'A3')]);

        DomainExceptions::assertThrown(new OnlyEligibleProduct('Remise', 'Print Vieux'), static fn () => $rule->replaceProduct($old, 'A4', $forest, 'A5'));
    }

    public function testRenamingAVariantRenamesTheConditionsOfItsTypeOnly(): void
    {
        $forest = $this->print('Forêt', ['A3']);
        $shirts = ProductType::create(TestWorkspace::get(), 'T-shirt', 'TSH');
        $shirt = Product::create(TestWorkspace::get(), 'TSH-MOU', 'Mousse', Money::cents(2_000), $shirts, ['A3']);
        $rule = $this->rule([ConditionSpec::on(1, $this->print, 'A3'), ConditionSpec::on(1, $forest, 'A3'), ConditionSpec::on(1, $shirts, 'A3'), ConditionSpec::on(1, $shirt, 'A3')]);

        $rule->renameVariant($this->print, 'a3', 'Grand');

        self::assertSame(['Grand', 'Grand', 'A3', 'A3'], array_map(static fn (DiscountCondition $c): ?string => $c->targets()[0]->variant(), $rule->conditions()));
    }

    public function testARuleUsesAVariantThroughATypeOrAProductCondition(): void
    {
        $forest = $this->print('Forêt', ['A4', 'A3']);
        $shirts = ProductType::create(TestWorkspace::get(), 'T-shirt', 'TSH');
        $shirts->defineVariants(['A5']);
        $onType = $this->rule([ConditionSpec::on(1, $this->print, 'A3')]);
        $onProduct = $this->rule([ConditionSpec::on(1, $forest, 'A4'), ConditionSpec::on(1, $shirts, 'A5')]);
        $withoutVariant = $this->rule([ConditionSpec::on(1, $this->print), ConditionSpec::on(1, $forest)]);

        self::assertTrue($onType->usesVariant($this->print, 'a3'));
        self::assertFalse($onType->usesVariant($this->print, 'A4'));
        self::assertTrue($onProduct->usesVariant($this->print, 'A4'));
        self::assertFalse($onProduct->usesVariant($this->print, 'A5'));
        self::assertTrue($onProduct->usesVariant($shirts, 'A5'));
        self::assertFalse($withoutVariant->usesVariant($this->print, 'A3'));
    }

    /**
     * @param list<string> $variants
     */
    private function print(string $name, array $variants = []): Product
    {
        return Product::create(TestWorkspace::get(), 'PRI-'.$name, $name, Money::cents(1_500), $this->print, $variants);
    }

    /**
     * @param list<ConditionSpec> $conditions
     */
    private function rule(array $conditions): DiscountRule
    {
        return DiscountRule::create(TestWorkspace::get(), 'Remise', $conditions, DiscountAction::fixedPrice(Money::cents(1_000)));
    }

    /**
     * @return list<array{string, int}>
     */
    private function targets(DiscountRule $rule): array
    {
        return array_map(static fn (DiscountCondition $c): array => [$c->name(), $c->quantity()], $rule->conditions());
    }

    public function testATypeCanBeWithdrawnUnlessItIsTheOnlyCondition(): void
    {
        $both = $this->rule([ConditionSpec::on(1, $this->sticker), ConditionSpec::on(2, $this->print)]);
        $both->withdrawType($this->print);
        self::assertSame([['Sticker', 1]], $this->targets($both));

        $this->expectException(OnlyEligibleType::class);
        $this->rule([ConditionSpec::on(2, $this->print)])->withdrawType($this->print);
    }

    public function testATypeCanBeNarrowedToACollectionNextToThePlainType(): void
    {
        $eevee = DesignCollection::start(TestWorkspace::get(), 'Eevee');
        $rule = $this->rule([new ConditionSpec(1, [new TargetSpec($this->print, collection: $eevee)]), ConditionSpec::on(1, $this->print)]);

        self::assertSame([['Print · Eevee', 1], ['Print', 1]], $this->targets($rule));
        self::assertSame(1, $rule->conditions()[0]->specificity());

        $this->expectException(DuplicateConditionTarget::class);
        $this->rule([new ConditionSpec(1, [new TargetSpec($this->print, collection: $eevee), new TargetSpec($this->print, collection: $eevee)])]);
    }

    public function testACollectionCanBeWithdrawnUnlessItIsTheOnlyCondition(): void
    {
        $eevee = DesignCollection::start(TestWorkspace::get(), 'Eevee');
        $both = $this->rule([new ConditionSpec(1, [new TargetSpec($this->print, collection: $eevee), new TargetSpec($this->sticker)]), ConditionSpec::on(1, $this->print)]);
        $both->withdrawCollection($eevee);
        self::assertSame([['Sticker', 1], ['Print', 1]], $this->targets($both));

        $this->expectException(OnlyEligibleCollection::class);
        $this->rule([new ConditionSpec(1, [new TargetSpec($this->print, collection: $eevee)])])->withdrawCollection($eevee);
    }

    public function testAConditionCanBeMetByAnyProductOrTypeOfItsGroup(): void
    {
        $forest = $this->print('Forêt', ['A4']);
        $rule = $this->rule([new ConditionSpec(3, [new TargetSpec($forest, 'A4'), new TargetSpec($this->sticker), new TargetSpec($this->print)])]);
        $condition = $rule->conditions()[0];

        self::assertSame(['Print Forêt · A4 / Sticker / Print', 3, 0], [$condition->name(), $condition->quantity(), $condition->specificity()]);
        self::assertTrue($condition->matches($this->sticker->id(), $this->sticker->type()->id(), null));
        self::assertTrue($condition->matches($forest->id(), $this->print->id(), 'A4'));
        self::assertFalse($condition->matches($this->holo->id(), $this->holo->type()->id(), null));
    }

    public function testAConditionNeedsATargetAndATargetAppearsOnceInTheRule(): void
    {
        DomainExceptions::assertThrown(new ConditionWithoutTarget(), fn () => $this->rule([new ConditionSpec(1, [])]));
        DomainExceptions::assertThrown(new DuplicateConditionTarget('Sticker'), fn () => $this->rule([new ConditionSpec(1, [new TargetSpec($this->sticker), new TargetSpec($this->sticker)])]));
        DomainExceptions::assertThrown(new DuplicateConditionTarget('Sticker'), fn () => $this->rule([ConditionSpec::on(1, $this->holo), new ConditionSpec(1, [new TargetSpec($this->print), new TargetSpec($this->sticker)]), ConditionSpec::on(1, $this->sticker)]));
    }

    public function testWithdrawingAProductShrinksItsGroupAndKeepsTheCondition(): void
    {
        $rule = $this->rule([new ConditionSpec(2, [new TargetSpec($this->sticker), new TargetSpec($this->holo)])]);

        $rule->withdrawProduct($this->holo);

        self::assertSame([['Sticker', 2]], $this->targets($rule));
        $this->expectException(OnlyEligibleProduct::class);
        $rule->withdrawProduct($this->sticker);
    }

    public function testWithdrawingATypeShrinksItsGroup(): void
    {
        $rule = $this->rule([new ConditionSpec(2, [new TargetSpec($this->print), new TargetSpec($this->sticker)])]);

        $rule->withdrawType($this->print);

        self::assertSame([['Sticker', 2]], $this->targets($rule));
    }

    public function testAReplacedProductOfAGroupIsRetargetedOrDroppedWhenItsTargetIsAlreadyThere(): void
    {
        $other = Product::create(TestWorkspace::get(), 'OTH', 'Autre', Money::cents(400), TestProductType::get());
        $retargeted = $this->rule([new ConditionSpec(2, [new TargetSpec($this->sticker), new TargetSpec($this->print)])]);
        $alreadyThere = $this->rule([new ConditionSpec(2, [new TargetSpec($this->sticker), new TargetSpec($this->holo)])]);

        $retargeted->replaceProduct($this->sticker, null, $other, null);
        $alreadyThere->replaceProduct($this->sticker, null, $this->holo, null);

        self::assertSame([['Autre / Print', 2]], $this->targets($retargeted));
        self::assertSame([['Holo', 2]], $this->targets($alreadyThere));
    }
}
