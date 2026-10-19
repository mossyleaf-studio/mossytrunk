<?php

declare(strict_types=1);

namespace App\Presentation\Web\Page;

use App\Presentation\Web\VuePage;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\HttpKernel\Attribute\MapQueryParameter;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;

#[AsController]
#[Route('/orders', name: 'orders', methods: ['GET'])]
final readonly class OrdersPageController
{
    public function __construct(private VuePage $page)
    {
    }

    public function __invoke(#[MapQueryParameter(filter: \FILTER_VALIDATE_REGEXP, flags: \FILTER_NULL_ON_FAILURE, options: ['regexp' => '/^'.Requirement::ULID.'$/'])] ?string $event = null): Response
    {
        $orders = null === $event ? '/api/orders' : "/api/orders?eventId=$event";

        return $this->page->render('OrdersPage', 'orders', preload: [$orders, '/api/products', '/api/events', '/api/services', '/api/sales-channels', '/api/discount-rules']);
    }
}
