<?php

declare(strict_types=1);

namespace App\Domain\Reporting\Analysis;

enum Quadrant: string
{
    case Star = 'star';
    case Workhorse = 'workhorse';
    case Puzzle = 'puzzle';
    case Dog = 'dog';

    public static function of(bool $popular, bool $profitable): self
    {
        return match (true) {
            $popular && $profitable => self::Star,
            $popular => self::Workhorse,
            $profitable => self::Puzzle,
            default => self::Dog,
        };
    }

    public function isPopular(): bool
    {
        return self::Star === $this || self::Workhorse === $this;
    }
}
