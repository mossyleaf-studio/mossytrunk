<?php

declare(strict_types=1);

namespace App\Presentation\Api\Planning;

use App\Application\Planning\GetEventPreparation\GetEventPreparationHandler;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;

#[Route('/api/events/{id}/preparation', name: 'api_events_preparation', requirements: ['id' => Requirement::ULID], methods: ['GET'], format: 'json')]
final class GetEventPreparationController extends AbstractController
{
    public function __invoke(string $id, GetEventPreparationHandler $getPreparation): JsonResponse
    {
        return $this->json($getPreparation($id));
    }
}
