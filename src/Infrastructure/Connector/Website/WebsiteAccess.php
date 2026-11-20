<?php

declare(strict_types=1);

namespace App\Infrastructure\Connector\Website;

final readonly class WebsiteAccess
{
    public function __construct(
        public string $address,
        public string $token,
        public string $label,
    ) {
    }

    public function host(): string
    {
        return (string) parse_url($this->address, \PHP_URL_HOST);
    }
}
