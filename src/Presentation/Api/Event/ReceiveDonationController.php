<?php

declare(strict_types=1);

namespace App\Presentation\Api\Event;

use App\Application\Event\ReceiveDonation\ReceiveDonation;
use App\Application\Event\ReceiveDonation\ReceiveDonationHandler;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;

#[Route('/api/events/{id}/donations', name: 'api_events_receive_donation', requirements: ['id' => Requirement::ULID], methods: ['POST'], format: 'json')]
final class ReceiveDonationController extends AbstractController
{
    public function __invoke(string $id, #[MapRequestPayload] DonationPayload $payload, ReceiveDonationHandler $receiveDonation): JsonResponse
    {
        $donationId = $receiveDonation(new ReceiveDonation($id, $payload->label, $payload->amount));

        return $this->json(['id' => (string) $donationId], Response::HTTP_CREATED);
    }
}
