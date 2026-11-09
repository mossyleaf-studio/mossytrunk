<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Doctrine;

use App\Domain\Planning\PlanningSettings;
use App\Domain\Planning\PlanningSettingsRepository;
use Doctrine\ORM\EntityManagerInterface;

final readonly class DoctrinePlanningSettingsRepository implements PlanningSettingsRepository
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private WorkspaceScope $scope,
    ) {
    }

    public function current(): ?PlanningSettings
    {
        return $this->scope->findOneBy(PlanningSettings::class, []);
    }

    public function add(PlanningSettings $settings): void
    {
        $this->entityManager->persist($settings);
    }
}
