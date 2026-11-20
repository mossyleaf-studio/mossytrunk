<?php

declare(strict_types=1);

namespace App\Presentation\Api\Integration;

use App\Application\Integration\Connectors;
use App\Application\Integration\ImportSales\ImportSalesHandler;
use App\Application\Integration\PublishCatalogue\PublishCatalogueHandler;
use App\Presentation\RouteRequirement;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/services/{service}/catalogue/publish', name: 'api_services_catalogue_publish', requirements: ['service' => RouteRequirement::SERVICE], methods: ['POST'], format: 'json')]
final class PublishCatalogueController extends AbstractController
{
    private const int TIME_LIMIT_SECONDS = 300;

    public function __invoke(string $service, Connectors $connectors, ImportSalesHandler $import, PublishCatalogueHandler $publish): JsonResponse
    {
        set_time_limit(self::TIME_LIMIT_SECONDS);
        $connectors->cataloguePublishing($service);
        $sales = $import($service);

        return $this->json(['sales' => $sales, 'catalogue' => $publish($service)]);
    }
}
