<?php

declare(strict_types=1);

namespace App\Application\Integration\Exception;

final class ServiceNotPublishingCatalogue extends ServiceUnavailable
{
    public function __construct(string $label)
    {
        parent::__construct('integration.service_not_publishing_catalogue', ['service' => $label]);
    }
}
