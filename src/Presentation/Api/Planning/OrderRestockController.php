<?php

declare(strict_types=1);

namespace App\Presentation\Api\Planning;

use App\Application\Planning\OrderRestock\OrderRestockHandler;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;

#[Route('/api/restocks/{id}/supplier-order', name: 'api_restocks_order', requirements: ['id' => Requirement::ULID], methods: ['PUT'], format: 'json')]
final class OrderRestockController extends AbstractController
{
    public function __invoke(string $id, #[MapRequestPayload] OrderRestockPayload $payload, OrderRestockHandler $order): Response
    {
        $order($id, $payload->supplierOrderId);

        return new Response(status: Response::HTTP_NO_CONTENT);
    }
}
