<?php

declare(strict_types=1);

namespace App\Domain\Planning\Exception;

final class PastRestockTarget extends InvalidPlanning
{
    public function __construct()
    {
        parent::__construct('planning.past_restock_target');
    }
}
