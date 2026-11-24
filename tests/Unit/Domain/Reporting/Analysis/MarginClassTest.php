<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Reporting\Analysis;

use App\Domain\Reporting\Analysis\MarginClass;
use App\Domain\Shared\Money;
use PHPUnit\Framework\TestCase;

final class MarginClassTest extends TestCase
{
    public function testAProductStaysInAWhileTheMarginBeforeItIsUnderEightyPercentEvenIfItCrossesIt(): void
    {
        $ranking = MarginClass::ranking([
            'dormant' => ['name' => 'Sticker Dormant', 'margin' => Money::zero()],
            'lichen' => ['name' => 'Sticker Lichen', 'margin' => Money::cents(3_000)],
            'nouveau' => ['name' => 'Sticker Nouveau', 'margin' => Money::cents(1_250)],
            'fougere' => ['name' => 'Sticker Fougère', 'margin' => Money::cents(2_000)],
            'holo' => ['name' => 'Sticker Holo', 'margin' => Money::cents(3_000)],
            'print' => ['name' => 'Print Forêt', 'margin' => Money::cents(4_800)],
            'mousse' => ['name' => 'Sticker Mousse', 'margin' => Money::cents(10_000)],
        ]);

        self::assertSame(['mousse', 'print', 'holo', 'lichen', 'fougere', 'nouveau', 'dormant'], array_keys($ranking));
        self::assertSame(['A', 'A', 'A', 'A', 'B', 'B', 'C'], array_map(static fn (array $row): string => $row['class']->value, array_values($ranking)));
        self::assertSame([10_000, 14_800, 17_800, 20_800, 22_800, 24_050, 24_050], array_map(static fn (array $row): int => $row['cumulative']->amount(), array_values($ranking)));
    }

    public function testProductsSoldAtALossDoNotShrinkTheTotalTheOthersAreRankedOn(): void
    {
        $ranking = MarginClass::ranking([
            'gift' => ['name' => 'Sticker offert', 'margin' => Money::cents(-2_000)],
            'mousse' => ['name' => 'Sticker Mousse', 'margin' => Money::cents(1_500)],
        ]);

        self::assertSame([MarginClass::A, MarginClass::C], array_column(array_values($ranking), 'class'));
        self::assertSame([1_500, 1_500], array_map(static fn (array $row): int => $row['cumulative']->amount(), array_values($ranking)));
    }

    public function testAProductWithoutMarginIsInC(): void
    {
        $ranking = MarginClass::ranking([
            'loss' => ['name' => 'Perte', 'margin' => Money::cents(-200)],
            'nothing' => ['name' => 'Rien', 'margin' => Money::zero()],
        ]);

        self::assertSame([MarginClass::C, MarginClass::C], array_column(array_values($ranking), 'class'));
    }
}
