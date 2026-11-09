<?php

declare(strict_types=1);

namespace App\Presentation\Api\Planning;

use App\Application\Planning\PlanRestock\PlanRestock;
use App\Application\Planning\PlanRestock\PlanRestockHandler;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/restocks', name: 'api_restocks_plan', methods: ['POST'], format: 'json')]
final class PlanRestockController extends AbstractController
{
    public function __invoke(#[MapRequestPayload] RestockPayload $payload, PlanRestockHandler $plan): JsonResponse
    {
        $restockId = $plan(new PlanRestock($payload->supplierId, $payload->note, $payload->eventId, $payload->leadDays));

        return $this->json(['id' => (string) $restockId], Response::HTTP_CREATED);
    }
}
