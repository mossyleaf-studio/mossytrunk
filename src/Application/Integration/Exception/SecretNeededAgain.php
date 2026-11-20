<?php

declare(strict_types=1);

namespace App\Application\Integration\Exception;

final class SecretNeededAgain extends ServiceUnavailable
{
    public function __construct(string $name)
    {
        parent::__construct('integration.secret_needed_again', ['name' => $name]);
    }
}
