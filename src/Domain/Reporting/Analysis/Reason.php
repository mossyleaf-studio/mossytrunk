<?php

declare(strict_types=1);

namespace App\Domain\Reporting\Analysis;

enum Reason: string
{
    case TooFewOrders = 'too_few_orders';
    case ArrivedLate = 'arrived_late';
    case NothingSold = 'nothing_sold';
    case UnpopularTail = 'unpopular_tail';
    case SoldOut = 'sold_out';
    case LowCover = 'low_cover';
    case Workhorse = 'workhorse';
    case Unpopular = 'unpopular';
    case TopEarner = 'top_earner';
    case Fine = 'fine';

    public static function of(Exposure $exposure, MarginClass $class, ?Quadrant $quadrant, StockLevel $stock): self
    {
        return match (true) {
            $exposure->tooFewOrders() => self::TooFewOrders,
            $exposure->arrivedLate() => self::ArrivedLate,
            0 === $exposure->units => self::NothingSold,
            $quadrant?->isPopular() ?? false => self::ofPopular($quadrant, $stock),
            default => self::ofUnpopular($class, $stock),
        };
    }

    public function verdict(): Verdict
    {
        return match ($this) {
            self::TooFewOrders, self::ArrivedLate => Verdict::TooEarly,
            self::NothingSold, self::UnpopularTail => Verdict::Discontinue,
            self::SoldOut, self::LowCover => Verdict::Restock,
            self::Workhorse => Verdict::Reprice,
            self::Unpopular => Verdict::Watch,
            self::TopEarner, self::Fine => Verdict::Keep,
        };
    }

    private static function ofPopular(?Quadrant $quadrant, StockLevel $stock): self
    {
        return match (true) {
            $stock->isShort() => self::shortOf($stock),
            Quadrant::Workhorse === $quadrant => self::Workhorse,
            default => self::Fine,
        };
    }

    private static function ofUnpopular(MarginClass $class, StockLevel $stock): self
    {
        return match ($class) {
            MarginClass::C => self::UnpopularTail,
            MarginClass::A => $stock->isShort() ? self::shortOf($stock) : self::TopEarner,
            MarginClass::B => self::Unpopular,
        };
    }

    private static function shortOf(StockLevel $stock): self
    {
        return StockLevel::Out === $stock ? self::SoldOut : self::LowCover;
    }
}
