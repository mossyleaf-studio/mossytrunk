<?php

declare(strict_types=1);

namespace App\Presentation\Api\Planning;

use App\Application\Planning\TickRestock\TickRestockHandler;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;

#[Route('/api/restocks/{id}/ordered', name: 'api_restocks_tick', requirements: ['id' => Requirement::ULID], methods: ['PUT'], format: 'json')]
final class TickRestockController extends AbstractController
{
    public function __invoke(string $id, #[MapRequestPayload] TickRestockPayload $payload, TickRestockHandler $tick): Response
    {
        $tick($id, (bool) $payload->ordered);

        return new Response(status: Response::HTTP_NO_CONTENT);
    }
}
