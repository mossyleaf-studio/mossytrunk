<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Doctrine;

use App\Domain\Planning\ReleaseTemplate;
use App\Domain\Planning\ReleaseTemplateRepository;
use Doctrine\ORM\EntityManagerInterface;

final readonly class DoctrineReleaseTemplateRepository implements ReleaseTemplateRepository
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private WorkspaceScope $scope,
    ) {
    }

    public function current(): ?ReleaseTemplate
    {
        return $this->scope->findOneBy(ReleaseTemplate::class, []);
    }

    public function add(ReleaseTemplate $template): void
    {
        $this->entityManager->persist($template);
    }
}
