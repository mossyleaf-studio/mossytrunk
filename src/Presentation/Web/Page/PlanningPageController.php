<?php

declare(strict_types=1);

namespace App\Presentation\Web\Page;

use App\Presentation\Web\VuePage;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;

#[AsController]
#[Route('/planning', name: 'planning', methods: ['GET'])]
final readonly class PlanningPageController
{
    public function __construct(private VuePage $page)
    {
    }

    public function __invoke(): Response
    {
        return $this->page->render('PlanningPage', 'planning', preload: ['/api/planning']);
    }
}
