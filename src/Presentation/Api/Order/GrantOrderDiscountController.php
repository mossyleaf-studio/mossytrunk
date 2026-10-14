<?php

declare(strict_types=1);

namespace App\Presentation\Api\Order;

use App\Application\Order\GrantOrderDiscount\GrantOrderDiscountHandler;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;

#[Route('/api/orders/{id}/discounts', name: 'api_orders_grant_discount', requirements: ['id' => Requirement::ULID], methods: ['POST'], format: 'json')]
final class GrantOrderDiscountController extends AbstractController
{
    public function __invoke(string $id, #[MapRequestPayload] GrantDiscountPayload $payload, GrantOrderDiscountHandler $grant): Response
    {
        $grant($id, $payload->ruleId);

        return new Response(status: Response::HTTP_NO_CONTENT);
    }
}
