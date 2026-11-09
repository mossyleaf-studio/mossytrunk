<?php

declare(strict_types=1);

namespace App\Domain\Planning;

interface ReleaseTemplateRepository
{
    public function current(): ?ReleaseTemplate;

    public function add(ReleaseTemplate $template): void;
}
