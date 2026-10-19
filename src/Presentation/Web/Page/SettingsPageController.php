<?php

declare(strict_types=1);

namespace App\Presentation\Web\Page;

use App\Domain\Integration\ServiceConnection;
use App\Domain\Integration\ServiceConnectionRepository;
use App\Presentation\Web\VuePage;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;

#[AsController]
#[Route('/settings', name: 'settings', methods: ['GET'])]
final readonly class SettingsPageController
{
    public function __construct(private VuePage $page, private ServiceConnectionRepository $connections)
    {
    }

    public function __invoke(): Response
    {
        $items = array_map(static fn (ServiceConnection $connection): string => "/api/services/{$connection->service()}/items", $this->connections->all());

        return $this->page->render('SettingsPage', 'settings', preload: ['/api/services', '/api/references/formats', '/api/products', ...$items]);
    }
}
