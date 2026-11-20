<?php

declare(strict_types=1);

namespace App\Infrastructure\Connector\Website;

use App\Application\Integration\ExternalSale;
use App\Infrastructure\Http\Json;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final class FakeWebsiteGateway implements WebsiteGateway
{
    /** @var list<ExternalSale>|null */
    private ?array $orders = null;

    public function __construct(
        private readonly WebsitePayloadMapper $mapper,
        #[Autowire('%kernel.project_dir%/tests/Fixtures/website/orders.json')]
        private readonly string $fixture,
    ) {
    }

    /**
     * @param list<ExternalSale> $orders
     */
    public function willReturn(array $orders): void
    {
        $this->orders = $orders;
    }

    public function paidOrders(WebsiteAccess $access): iterable
    {
        if (null !== $this->orders) {
            return $this->orders;
        }

        $payload = json_decode((string) file_get_contents($this->fixture), true, flags: \JSON_THROW_ON_ERROR);

        return $this->mapper->sales(Json::object($payload)['orders'] ?? null);
    }
}
