<?php

declare(strict_types=1);

namespace App\Domain\Reporting\Analysis;

enum Verdict: string
{
    case TooEarly = 'too_early';
    case Discontinue = 'discontinue';
    case Restock = 'restock';
    case Reprice = 'reprice';
    case Watch = 'watch';
    case Keep = 'keep';

    public static function of(Exposure $exposure, MarginClass $class, ?Quadrant $quadrant, StockLevel $stock): self
    {
        $popular = $quadrant?->isPopular() ?? false;

        return match (true) {
            $exposure->tooEarly() => self::TooEarly,
            self::isDead($exposure, $popular, $class) => self::Discontinue,
            self::isRunningOut($popular, $class, $stock) => self::Restock,
            Quadrant::Workhorse === $quadrant => self::Reprice,
            !$popular && MarginClass::A !== $class => self::Watch,
            default => self::Keep,
        };
    }

    private static function isDead(Exposure $exposure, bool $popular, MarginClass $class): bool
    {
        return 0 === $exposure->units || !$popular && MarginClass::C === $class;
    }

    private static function isRunningOut(bool $popular, MarginClass $class, StockLevel $stock): bool
    {
        return ($popular || MarginClass::A === $class) && $stock->isShort();
    }
}
