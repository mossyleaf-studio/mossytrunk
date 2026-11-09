<?php

declare(strict_types=1);

namespace App\Presentation\Api\Event;

use App\Application\Event\UpdateEvent\UpdateEvent;
use App\Application\Event\UpdateEvent\UpdateEventHandler;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;

#[Route('/api/events/{id}', name: 'api_events_update', requirements: ['id' => Requirement::ULID], methods: ['PUT'], format: 'json')]
final class UpdateEventController extends AbstractController
{
    public function __invoke(string $id, #[MapRequestPayload] EventPayload $payload, UpdateEventHandler $updateEvent): Response
    {
        $updateEvent(new UpdateEvent($id, $payload->name, $payload->location, $payload->start(), $payload->end(), $payload->readyDaysBefore));

        return new Response(status: Response::HTTP_NO_CONTENT);
    }
}
