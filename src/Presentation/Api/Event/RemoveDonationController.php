<?php

declare(strict_types=1);

namespace App\Presentation\Api\Event;

use App\Application\Event\RemoveDonation\RemoveDonation;
use App\Application\Event\RemoveDonation\RemoveDonationHandler;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;

#[Route('/api/events/{id}/donations/{donationId}', name: 'api_events_remove_donation', requirements: ['id' => Requirement::ULID, 'donationId' => Requirement::ULID], methods: ['DELETE'], format: 'json')]
final class RemoveDonationController extends AbstractController
{
    public function __invoke(string $id, string $donationId, RemoveDonationHandler $removeDonation): Response
    {
        $removeDonation(new RemoveDonation($id, $donationId));

        return new Response(status: Response::HTTP_NO_CONTENT);
    }
}
