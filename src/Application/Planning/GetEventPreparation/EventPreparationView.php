<?php

declare(strict_types=1);

namespace App\Application\Planning\GetEventPreparation;

use App\Application\Planning\ReleaseView;
use App\Application\Planning\RestockView;

final readonly class EventPreparationView
{
    /**
     * @param array{id: string, name: string, start: string, end: string} $event
     * @param list<ReleaseView>                                           $releases
     * @param list<RestockView>                                           $restocks
     */
    public function __construct(
        public array $event,
        public string $readyBy,
        public int $readyDaysBefore,
        public array $releases,
        public array $restocks,
    ) {
    }
}
