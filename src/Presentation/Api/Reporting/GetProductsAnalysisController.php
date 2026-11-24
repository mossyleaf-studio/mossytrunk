<?php

declare(strict_types=1);

namespace App\Presentation\Api\Reporting;

use App\Application\Reporting\ProductsAnalysis\GetProductsAnalysisHandler;
use App\Application\Reporting\ReportPeriod;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Attribute\MapQueryParameter;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/reports/products/analysis', name: 'api_reports_products_analysis', methods: ['GET'], format: 'json')]
final class GetProductsAnalysisController extends AbstractController
{
    public function __invoke(GetProductsAnalysisHandler $getAnalysis, #[MapQueryParameter] string $period = ReportPeriod::LAST_TWELVE_MONTHS): JsonResponse
    {
        return $this->json($getAnalysis($period));
    }
}
