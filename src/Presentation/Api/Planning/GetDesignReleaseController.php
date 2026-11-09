<?php

declare(strict_types=1);

namespace App\Presentation\Api\Planning;

use App\Application\Planning\GetDesignRelease\GetDesignReleaseHandler;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;

#[Route('/api/designs/{id}/release', name: 'api_designs_release_show', requirements: ['id' => Requirement::ULID], methods: ['GET'], format: 'json')]
final class GetDesignReleaseController extends AbstractController
{
    public function __invoke(string $id, GetDesignReleaseHandler $getRelease): JsonResponse
    {
        return $this->json($getRelease($id));
    }
}
