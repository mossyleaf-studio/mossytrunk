<?php

declare(strict_types=1);

namespace App\Presentation\Web\Page;

use App\Presentation\Web\VuePage;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;

#[AsController]
#[Route('/designs/{id}', name: 'design_show', requirements: ['id' => Requirement::ULID], methods: ['GET'])]
final readonly class DesignDetailPageController
{
    public function __construct(private VuePage $page)
    {
    }

    public function __invoke(string $id): Response
    {
        return $this->page->render('DesignDetailPage', 'design', ['designId' => $id], preload: ["/api/designs/$id", '/api/gabarits', '/api/designs', '/api/product-types', "/api/designs/$id/release"]);
    }
}
