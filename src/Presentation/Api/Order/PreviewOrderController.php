<?php

declare(strict_types=1);

namespace App\Presentation\Api\Order;

use App\Application\Order\PreviewOrder\PreviewOrderHandler;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/orders/preview', name: 'api_orders_preview', methods: ['POST'], format: 'json')]
final class PreviewOrderController extends AbstractController
{
    public function __invoke(#[MapRequestPayload] OrderPayload $payload, PreviewOrderHandler $previewOrder): JsonResponse
    {
        return $this->json($previewOrder($payload->placedAt(), $payload->requestedLines(), $payload->discountRuleIds, $payload->outsideEvents));
    }
}
