<?php

declare(strict_types=1);

namespace App\Application\Planning\GetReleaseTemplate;

use App\Domain\Planning\ReleaseTemplateRepository;

final readonly class GetReleaseTemplateHandler
{
    public function __construct(
        private ReleaseTemplateRepository $templates,
    ) {
    }

    public function __invoke(): ReleaseTemplateView
    {
        return new ReleaseTemplateView($this->templates->current()?->steps() ?? []);
    }
}
