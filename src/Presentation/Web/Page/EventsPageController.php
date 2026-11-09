<?php

declare(strict_types=1);

namespace App\Presentation\Web\Page;

use App\Presentation\Web\VuePage;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;

#[AsController]
#[Route('/events', name: 'events', methods: ['GET'])]
final readonly class EventsPageController
{
    public function __construct(private VuePage $page)
    {
    }

    public function __invoke(): Response
    {
        return $this->page->render('EventsPage', 'events', preload: ['/api/events', '/api/planning-settings']);
    }
}
