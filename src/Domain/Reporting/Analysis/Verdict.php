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
}
