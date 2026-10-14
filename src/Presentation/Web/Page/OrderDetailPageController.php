<?php

declare(strict_types=1);

namespace App\Presentation\Web\Page;

use App\Presentation\Web\VuePage;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;

#[AsController]
#[Route('/orders/{id}', name: 'order_show', requirements: ['id' => Requirement::ULID], methods: ['GET'])]
final readonly class OrderDetailPageController
{
    public function __construct(private VuePage $page)
    {
    }

    public function __invoke(string $id): Response
    {
        return $this->page->render('OrderDetailPage', 'order', ['orderId' => $id], preload: ["/api/orders/$id", '/api/products', '/api/sales-channels', '/api/discount-rules']);
    }
}
