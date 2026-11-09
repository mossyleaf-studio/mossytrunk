<?php

declare(strict_types=1);

namespace App\Presentation\Api\Planning;

use App\Application\Planning\GetPlanning\GetPlanningHandler;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/planning', name: 'api_planning_show', methods: ['GET'], format: 'json')]
final class GetPlanningController extends AbstractController
{
    public function __invoke(GetPlanningHandler $getPlanning): JsonResponse
    {
        return $this->json($getPlanning());
    }
}
