<?php

declare(strict_types=1);

namespace App\Presentation\Api\Integration;

use App\Application\Integration\LinkExternalItem\LinkExternalItemHandler;
use App\Presentation\RouteRequirement;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;

#[Route('/api/services/{service}/items/{id}', name: 'api_services_items_link', requirements: ['service' => RouteRequirement::SERVICE, 'id' => Requirement::ULID], methods: ['PUT'], format: 'json')]
final class LinkExternalItemController extends AbstractController
{
    public function __invoke(string $service, string $id, #[MapRequestPayload] LinkExternalItemPayload $payload, LinkExternalItemHandler $link): Response
    {
        $link($service, $id, $payload->requestedLines());

        return new Response(status: Response::HTTP_NO_CONTENT);
    }
}
