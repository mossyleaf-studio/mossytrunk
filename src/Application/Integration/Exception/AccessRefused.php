<?php

declare(strict_types=1);

namespace App\Application\Integration\Exception;

final class AccessRefused extends ServiceUnavailable
{
    public function __construct(string $label)
    {
        parent::__construct('integration.access_refused', ['service' => $label]);
    }
}
