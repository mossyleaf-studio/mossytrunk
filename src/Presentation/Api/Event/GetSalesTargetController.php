<?php

declare(strict_types=1);

namespace App\Presentation\Api\Event;

use App\Application\Event\GetSalesTarget\GetSalesTargetHandler;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;

#[Route('/api/events/{id}/target', name: 'api_events_target', requirements: ['id' => Requirement::ULID], methods: ['GET'], format: 'json')]
final class GetSalesTargetController extends AbstractController
{
    public function __invoke(string $id, GetSalesTargetHandler $getTarget): JsonResponse
    {
        return $this->json($getTarget($id));
    }
}
