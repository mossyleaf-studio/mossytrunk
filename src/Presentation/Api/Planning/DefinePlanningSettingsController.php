<?php

declare(strict_types=1);

namespace App\Presentation\Api\Planning;

use App\Application\Planning\DefinePlanningSettings\DefinePlanningSettings;
use App\Application\Planning\DefinePlanningSettings\DefinePlanningSettingsHandler;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/planning-settings', name: 'api_planning_settings_define', methods: ['PUT'], format: 'json')]
final class DefinePlanningSettingsController extends AbstractController
{
    public function __invoke(#[MapRequestPayload] PlanningSettingsPayload $payload, DefinePlanningSettingsHandler $define): Response
    {
        $define(new DefinePlanningSettings($payload->toSteps(), $payload->readyDaysBefore));

        return new Response(status: Response::HTTP_NO_CONTENT);
    }
}
