<?php

declare(strict_types=1);

namespace App\Presentation\Api\Planning;

use App\Application\Planning\DefineReleaseTemplate\DefineReleaseTemplateHandler;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/release-template', name: 'api_release_template_define', methods: ['PUT'], format: 'json')]
final class DefineReleaseTemplateController extends AbstractController
{
    public function __invoke(#[MapRequestPayload] ReleaseTemplatePayload $payload, DefineReleaseTemplateHandler $define): Response
    {
        $define($payload->toSteps());

        return new Response(status: Response::HTTP_NO_CONTENT);
    }
}
