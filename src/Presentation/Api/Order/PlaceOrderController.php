<?php

declare(strict_types=1);

namespace App\Presentation\Api\Order;

use App\Application\Order\PlaceOrder\PlaceOrder;
use App\Application\Order\PlaceOrder\PlaceOrderHandler;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/orders', name: 'api_orders_place', methods: ['POST'], format: 'json')]
final class PlaceOrderController extends AbstractController
{
    public function __invoke(#[MapRequestPayload] OrderPayload $payload, PlaceOrderHandler $placeOrder): JsonResponse
    {
        $order = $placeOrder(new PlaceOrder($payload->placedAt(), $payload->requestedLines(), $payload->discountRuleIds, $payload->outsideEvents, $payload->note));

        return $this->json(['id' => (string) $order->id(), 'reference' => $order->reference()], Response::HTTP_CREATED);
    }
}
