<?php

declare(strict_types=1);

namespace App\Presentation\Api\Order;

use App\Application\Order\WithdrawOrderDiscount\WithdrawOrderDiscountHandler;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;

#[Route('/api/orders/{id}/discounts/{ruleId}', name: 'api_orders_withdraw_discount', requirements: ['id' => Requirement::ULID, 'ruleId' => Requirement::ULID], methods: ['DELETE'], format: 'json')]
final class WithdrawOrderDiscountController extends AbstractController
{
    public function __invoke(string $id, string $ruleId, WithdrawOrderDiscountHandler $withdraw): Response
    {
        $withdraw($id, $ruleId);

        return new Response(status: Response::HTTP_NO_CONTENT);
    }
}
