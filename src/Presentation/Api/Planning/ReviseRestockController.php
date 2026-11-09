<?php

declare(strict_types=1);

namespace App\Presentation\Api\Planning;

use App\Application\Planning\ReviseRestock\ReviseRestock;
use App\Application\Planning\ReviseRestock\ReviseRestockHandler;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;

#[Route('/api/restocks/{id}', name: 'api_restocks_revise', requirements: ['id' => Requirement::ULID], methods: ['PUT'], format: 'json')]
final class ReviseRestockController extends AbstractController
{
    public function __invoke(string $id, #[MapRequestPayload] RestockPayload $payload, ReviseRestockHandler $revise): Response
    {
        $revise(new ReviseRestock($id, $payload->supplierId, $payload->note, $payload->day, $payload->eventId, $payload->leadDays));

        return new Response(status: Response::HTTP_NO_CONTENT);
    }
}
