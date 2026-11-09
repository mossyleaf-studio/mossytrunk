<?php

declare(strict_types=1);

namespace App\Presentation\Api\Planning;

use App\Application\Planning\TickReleaseStep\TickReleaseStepHandler;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;

#[Route('/api/releases/{id}/steps', name: 'api_releases_step_tick', requirements: ['id' => Requirement::ULID], methods: ['PUT'], format: 'json')]
final class TickReleaseStepController extends AbstractController
{
    public function __invoke(string $id, #[MapRequestPayload] TickReleaseStepPayload $payload, TickReleaseStepHandler $tick): Response
    {
        $tick($id, $payload->step, true === $payload->done);

        return new Response(status: Response::HTTP_NO_CONTENT);
    }
}
