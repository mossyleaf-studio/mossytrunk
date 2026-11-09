<?php

declare(strict_types=1);

namespace App\Presentation\Api\Event;

use Symfony\Component\Validator\Constraints as Assert;

final readonly class EventPayload
{
    public function __construct(
        #[Assert\NotBlank(message: 'name.required')]
        #[Assert\Length(max: 255)]
        public string $name = '',
        #[Assert\NotBlank(message: 'event.location.required')]
        #[Assert\Length(max: 255)]
        public string $location = '',
        #[Assert\NotBlank(message: 'date.start.required')]
        #[Assert\Date(message: 'date.invalid')]
        public string $startDate = '',
        #[Assert\NotBlank(message: 'date.end.required')]
        #[Assert\Date(message: 'date.invalid')]
        public string $endDate = '',
        public ?int $readyDaysBefore = null,
    ) {
    }

    public function start(): \DateTimeImmutable
    {
        return new \DateTimeImmutable($this->startDate);
    }

    public function end(): \DateTimeImmutable
    {
        return new \DateTimeImmutable($this->endDate);
    }
}
