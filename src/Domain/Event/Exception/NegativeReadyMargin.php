<?php

declare(strict_types=1);

namespace App\Domain\Event\Exception;

final class NegativeReadyMargin extends InvalidEvent
{
    public function __construct()
    {
        parent::__construct('event.negative_ready_margin');
    }
}
