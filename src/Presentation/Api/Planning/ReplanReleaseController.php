<?php

declare(strict_types=1);

namespace App\Presentation\Api\Planning;

use App\Application\Planning\ReplanRelease\ReplanRelease;
use App\Application\Planning\ReplanRelease\ReplanReleaseHandler;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;

#[Route('/api/releases/{id}', name: 'api_releases_replan', requirements: ['id' => Requirement::ULID], methods: ['PUT'], format: 'json')]
final class ReplanReleaseController extends AbstractController
{
    public function __invoke(string $id, #[MapRequestPayload] ReleasePayload $payload, ReplanReleaseHandler $replan): Response
    {
        $replan(new ReplanRelease($id, $payload->day, $payload->eventId, $payload->steps()));

        return new Response(status: Response::HTTP_NO_CONTENT);
    }
}
