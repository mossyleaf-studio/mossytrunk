<?php

declare(strict_types=1);

namespace App\Presentation\Web\Page;

use App\Domain\Integration\ServiceConnection;
use App\Domain\Integration\ServiceConnectionRepository;
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
    public function __construct(private VuePage $page, private ServiceConnectionRepository $connections)
    {
    }

    public function __invoke(#[MapQueryParameter(filter: \FILTER_VALIDATE_REGEXP, flags: \FILTER_NULL_ON_FAILURE, options: ['regexp' => '/^'.Requirement::ULID.'$/'])] ?string $event = null): Response
    {
        $orders = null === $event ? '/api/orders' : "/api/orders?eventId=$event";

        $items = array_map(static fn (ServiceConnection $connection): string => "/api/services/{$connection->service()}/items", $this->connections->all());

        return $this->page->render('OrdersPage', 'orders', preload: [$orders, '/api/products', '/api/events', '/api/services', '/api/sales-channels', '/api/discount-rules', ...$items]);
    }
}
