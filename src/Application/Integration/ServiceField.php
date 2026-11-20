<?php

declare(strict_types=1);

namespace App\Application\Integration;

final readonly class ServiceField
{
    public function __construct(
        public string $name,
        public string $label,
        public bool $secret = false,
        public bool $required = true,
        public ?string $pattern = null,
        public ?string $patternMessage = null,
        public ?string $hint = null,
        public int $maxLength = 255,
        public bool $uppercase = false,
        public bool $bindsSecrets = false,
    ) {
    }

    public function normalize(string $value): string
    {
        $value = trim($value);

        return $this->uppercase ? strtoupper($value) : $value;
    }
}
