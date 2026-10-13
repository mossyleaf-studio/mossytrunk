<?php

declare(strict_types=1);

namespace App\Application\Discount;

final readonly class TargetDefinition
{
    public const string PRODUCT = 'product';
    public const string TYPE = 'type';

    public function __construct(
        public string $kind,
        public string $targetId,
        public ?string $variant = null,
        public ?string $collectionId = null,
    ) {
    }
}
