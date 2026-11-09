<?php

declare(strict_types=1);

namespace App\Presentation\Api\Planning;

use App\Application\Planning\CancelRelease\CancelReleaseHandler;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;

#[Route('/api/releases/{id}', name: 'api_releases_cancel', requirements: ['id' => Requirement::ULID], methods: ['DELETE'], format: 'json')]
final class CancelReleaseController extends AbstractController
{
    public function __invoke(string $id, CancelReleaseHandler $cancel): Response
    {
        $cancel($id);

        return new Response(status: Response::HTTP_NO_CONTENT);
    }
}
