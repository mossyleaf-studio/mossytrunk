<?php

declare(strict_types=1);

namespace App\Presentation\Api\Planning;

use App\Application\Planning\GetReleaseTemplate\GetReleaseTemplateHandler;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/release-template', name: 'api_release_template_show', methods: ['GET'], format: 'json')]
final class GetReleaseTemplateController extends AbstractController
{
    public function __invoke(GetReleaseTemplateHandler $getTemplate): JsonResponse
    {
        return $this->json($getTemplate());
    }
}
