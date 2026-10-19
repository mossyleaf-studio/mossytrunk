<?php

declare(strict_types=1);

namespace App\Domain\Integration\Exception;

final class EmptyItemLink extends InvalidExternalItem
{
    public function __construct()
    {
        parent::__construct('integration.empty_item_link');
    }
}
