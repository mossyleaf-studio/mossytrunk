<?php

declare(strict_types=1);

namespace App\Domain\Integration\Exception;

use App\Domain\Shared\Exception\DomainException;

abstract class InvalidExternalItem extends DomainException
{
}
