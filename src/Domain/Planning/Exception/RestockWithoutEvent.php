<?php

declare(strict_types=1);

namespace App\Domain\Planning\Exception;

final class RestockWithoutEvent extends InvalidPlanning
{
    public function __construct()
    {
        parent::__construct('planning.restock_without_event');
    }
}
