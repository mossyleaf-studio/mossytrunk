<?php

declare(strict_types=1);

namespace App\Presentation\Web\Page;

use App\Application\Reporting\ReportPeriod;
use App\Presentation\Web\VuePage;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\HttpKernel\Attribute\MapQueryParameter;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;

#[AsController]
#[Route('/reports/products', name: 'product_reports', methods: ['GET'])]
final readonly class ProductReportsPageController
{
    public function __construct(private VuePage $page)
    {
    }

    public function __invoke(#[MapQueryParameter] string $period = ReportPeriod::LAST_TWELVE_MONTHS, #[MapQueryParameter] string $product = ''): Response
    {
        $query = '?period='.rawurlencode($period);
        $preload = ['/api/reports/products'.$query, '/api/reports/products/analysis'.$query];
        if (1 === preg_match('/^'.Requirement::ULID.'$/', $product)) {
            $preload[] = "/api/reports/products/$product$query";
        }

        return $this->page->render('ProductReportsPage', 'productReports', preload: $preload);
    }
}
