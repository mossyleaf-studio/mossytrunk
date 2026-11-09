<?php

declare(strict_types=1);

namespace App\Presentation\Api\Planning;

use App\Application\Planning\PlanDesignRelease\PlanDesignRelease;
use App\Application\Planning\PlanDesignRelease\PlanDesignReleaseHandler;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;

#[Route('/api/designs/{id}/release', name: 'api_designs_release_plan', requirements: ['id' => Requirement::ULID], methods: ['POST'], format: 'json')]
final class PlanDesignReleaseController extends AbstractController
{
    public function __invoke(string $id, #[MapRequestPayload] ReleasePayload $payload, PlanDesignReleaseHandler $plan): JsonResponse
    {
        $releaseId = $plan(new PlanDesignRelease($id, $payload->day, $payload->eventId, $payload->steps()));

        return $this->json(['id' => (string) $releaseId], Response::HTTP_CREATED);
    }
}
