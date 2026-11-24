<?php

declare(strict_types=1);

namespace App\Presentation\Web\Page;

use App\Application\Reporting\ReportPeriod;
use App\Presentation\Web\VuePage;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\HttpKernel\Attribute\MapQueryParameter;
use Symfony\Component\Routing\Attribute\Route;

#[AsController]
#[Route('/reports/products', name: 'product_reports', methods: ['GET'])]
final readonly class ProductReportsPageController
{
    public function __construct(private VuePage $page)
    {
    }

    public function __invoke(#[MapQueryParameter] string $period = ReportPeriod::LAST_TWELVE_MONTHS): Response
    {
        $query = '?period='.rawurlencode($period);

        return $this->page->render('ProductReportsPage', 'productReports', preload: ['/api/reports/products'.$query, '/api/reports/products/analysis'.$query]);
    }
}
