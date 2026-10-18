<?php

declare(strict_types=1);

namespace App\Presentation\Api\Order;

use App\Application\Order\AnnotateOrder\AnnotateOrderHandler;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;

#[Route('/api/orders/{id}/note', name: 'api_orders_note', requirements: ['id' => Requirement::ULID], methods: ['PUT'], format: 'json')]
final class AnnotateOrderController extends AbstractController
{
    public function __invoke(string $id, #[MapRequestPayload] NotePayload $payload, AnnotateOrderHandler $annotate): Response
    {
        $annotate($id, $payload->note);

        return new Response(status: Response::HTTP_NO_CONTENT);
    }
}
