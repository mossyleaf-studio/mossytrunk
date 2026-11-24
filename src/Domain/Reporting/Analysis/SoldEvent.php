<?php

declare(strict_types=1);

namespace App\Domain\Reporting\Analysis;

use Symfony\Component\Uid\Ulid;

final readonly class SoldEvent
{
    public function __construct(
        public Ulid $id,
        public string $name,
        public string $from,
        public string $to,
    ) {
    }
}
