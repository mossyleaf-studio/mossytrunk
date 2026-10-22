<?php

declare(strict_types=1);

namespace App\Presentation\Web\Page;

use App\Presentation\Web\VuePage;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;

#[AsController]
#[Route('/events/{id}', name: 'event_show', requirements: ['id' => Requirement::ULID], methods: ['GET'])]
final readonly class EventDetailPageController
{
    public function __construct(private VuePage $page)
    {
    }

    public function __invoke(string $id): Response
    {
        return $this->page->render('EventDetailPage', 'event', ['eventId' => $id], preload: ["/api/events/$id", "/api/events/$id/report", "/api/events/$id/target", '/api/product-types', "/api/events/$id/stock-checks"]);
    }
}
