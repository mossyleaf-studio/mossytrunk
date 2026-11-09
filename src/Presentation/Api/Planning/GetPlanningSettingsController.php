<?php

declare(strict_types=1);

namespace App\Presentation\Api\Planning;

use App\Application\Planning\GetPlanningSettings\GetPlanningSettingsHandler;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/planning-settings', name: 'api_planning_settings_show', methods: ['GET'], format: 'json')]
final class GetPlanningSettingsController extends AbstractController
{
    public function __invoke(GetPlanningSettingsHandler $getSettings): JsonResponse
    {
        return $this->json($getSettings());
    }
}
