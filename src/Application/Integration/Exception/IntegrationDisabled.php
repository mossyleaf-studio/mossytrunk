<?php

declare(strict_types=1);

namespace App\Application\Integration\Exception;

final class IntegrationDisabled extends ServiceUnavailable
{
    public function __construct(string $label)
    {
        parent::__construct('integration.integration_disabled', ['service' => $label]);
    }
}
