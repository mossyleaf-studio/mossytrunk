<?php

declare(strict_types=1);

namespace App\Application\Planning\DefineReleaseTemplate;

use App\Application\Transaction;
use App\Application\WorkspaceContext;
use App\Domain\Planning\ReleaseTemplate;
use App\Domain\Planning\ReleaseTemplateRepository;

final readonly class DefineReleaseTemplateHandler
{
    public function __construct(
        private ReleaseTemplateRepository $templates,
        private WorkspaceContext $workspace,
        private Transaction $transaction,
    ) {
    }

    /**
     * @param list<array{label: string, days: int}> $steps
     */
    public function __invoke(array $steps): void
    {
        $template = $this->templates->current();
        if (null === $template) {
            $template = ReleaseTemplate::of($this->workspace->current());
            $this->templates->add($template);
        }
        $template->define($steps);
        $this->transaction->commit();
    }
}
