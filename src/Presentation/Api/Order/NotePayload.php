<?php

declare(strict_types=1);

namespace App\Presentation\Api\Order;

use Symfony\Component\Validator\Constraints as Assert;

final readonly class NotePayload
{
    public function __construct(
        #[Assert\Length(max: 1000, maxMessage: 'note.tooLong')]
        public ?string $note = null,
    ) {
    }
}
