<?php

declare(strict_types=1);

namespace App\Presentation\Api\Order;

use App\Application\Order\SplitOrderLine\SplitOrderLine;
use App\Application\Order\SplitOrderLine\SplitOrderLineHandler;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;

#[Route('/api/orders/{id}/lines/{lineId}/split', name: 'api_orders_split_line', requirements: ['id' => Requirement::ULID, 'lineId' => Requirement::ULID], methods: ['POST'], format: 'json')]
final class SplitOrderLineController extends AbstractController
{
    public function __invoke(string $id, string $lineId, #[MapRequestPayload] SplitOrderLinePayload $payload, SplitOrderLineHandler $splitLine): Response
    {
        $splitLine(new SplitOrderLine($id, $lineId, $payload->requestedLines()));

        return new Response(status: Response::HTTP_NO_CONTENT);
    }
}
