<?php

declare(strict_types=1);

namespace App\Infrastructure\Connector\Website;

use App\Application\Integration\CatalogueChanges;
use App\Application\Integration\Exception\CatalogueRefused;
use App\Application\Integration\Exception\ServiceUnreachable;
use App\Application\Integration\ExternalSale;
use App\Application\Integration\StockedProduct;
use App\Infrastructure\Http\Json;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final class FakeWebsiteGateway implements WebsiteGateway
{
    public const string UNREACHABLE_HOST = 'down.example';
    public const string REFUSING_HOST = 'refusing.example';

    /** @var list<ExternalSale>|null */
    private ?array $orders = null;

    /** @var list<StockedProduct> */
    private array $published = [];

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
        if (self::UNREACHABLE_HOST === $access->host()) {
            throw new ServiceUnreachable($access->label, $access->host());
        }
        if (null !== $this->orders) {
            return $this->orders;
        }

        $payload = json_decode((string) file_get_contents($this->fixture), true, flags: \JSON_THROW_ON_ERROR);

        return $this->mapper->sales(Json::object($payload)['orders'] ?? null);
    }

    public function replaceCatalogue(WebsiteAccess $access, array $products): CatalogueChanges
    {
        if (self::REFUSING_HOST === $access->host()) {
            throw new CatalogueRefused($access->label, 'HTTP 422');
        }
        $known = array_column($this->published, 'id');
        $sent = array_column($products, 'id');
        $this->published = $products;

        return new CatalogueChanges(\count(array_diff($sent, $known)), \count(array_intersect($sent, $known)), \count(array_diff($known, $sent)));
    }

    /**
     * @return list<StockedProduct>
     */
    public function published(): array
    {
        return $this->published;
    }
}
