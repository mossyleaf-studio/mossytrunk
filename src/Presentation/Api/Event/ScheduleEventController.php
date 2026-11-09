<?php

declare(strict_types=1);

namespace App\Presentation\Api\Event;

use App\Application\Event\ScheduleEvent\ScheduleEvent;
use App\Application\Event\ScheduleEvent\ScheduleEventHandler;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/events', name: 'api_events_create', methods: ['POST'], format: 'json')]
final class ScheduleEventController extends AbstractController
{
    public function __invoke(#[MapRequestPayload] EventPayload $payload, ScheduleEventHandler $scheduleEvent): JsonResponse
    {
        $id = $scheduleEvent(new ScheduleEvent($payload->name, $payload->location, $payload->start(), $payload->end(), $payload->readyDaysBefore));

        return $this->json(['id' => (string) $id], Response::HTTP_CREATED);
    }
}
