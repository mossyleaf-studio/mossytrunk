<?php

declare(strict_types=1);

namespace App\Application\Integration\Exception;

use App\Domain\Shared\Exception\DomainException;

final class EmptyCatalogue extends DomainException
{
    public function __construct(string $service)
    {
        parent::__construct('integration.empty_catalogue', ['service' => $service]);
    }
}
