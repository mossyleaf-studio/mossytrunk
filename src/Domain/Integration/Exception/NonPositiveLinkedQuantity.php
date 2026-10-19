<?php

declare(strict_types=1);

namespace App\Domain\Integration\Exception;

final class NonPositiveLinkedQuantity extends InvalidExternalItem
{
    public function __construct(string $product)
    {
        parent::__construct('integration.non_positive_linked_quantity', ['product' => $product]);
    }
}
