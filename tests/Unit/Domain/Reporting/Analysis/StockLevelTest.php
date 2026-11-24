<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Reporting\Analysis;

use App\Domain\Reporting\Analysis\StockLevel;
use PHPUnit\Framework\TestCase;

final class StockLevelTest extends TestCase
{
    public function testStockIsLowWhenItDoesNotLastTheBusiestEventButAnUnsoldProductIsNeverLow(): void
    {
        self::assertSame(
            [StockLevel::Low, StockLevel::Ok, StockLevel::Out, StockLevel::Ok, StockLevel::Ok],
            [StockLevel::of(20, 102, 160), StockLevel::of(40, 408, 160), StockLevel::of(0, 0, 160), StockLevel::of(20, null, 160), StockLevel::of(25, 160, 160)],
        );
    }
}
