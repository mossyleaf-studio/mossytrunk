<?php

declare(strict_types=1);

namespace App\Application\Integration\PublishReferences;

use App\Application\Integration\AddedConnection;
use App\Application\Integration\ConnectionSession;
use App\Application\Integration\Connectors;
use App\Application\Integration\ItemReference;
use App\Domain\Integration\ExternalItemRepository;
use App\Domain\Product\Product;
use App\Domain\Product\ProductRepository;
use App\Domain\Product\Sku;
use Symfony\Component\Uid\Ulid;

final readonly class PublishReferencesHandler
{
    public function __construct(
        private Connectors $connectors,
        private AddedConnection $addedConnections,
        private ConnectionSession $session,
        private ExternalItemRepository $items,
        private ProductRepository $products,
    ) {
    }

    public function __invoke(string $service): PublishedReferences
    {
        $connector = $this->connectors->publishing($service);
        $description = $connector->describe();
        $connection = $this->addedConnections->of($connector);

        $singles = [];
        foreach ($this->items->ofService($service) as $item) {
            if ($item->isSingle()) {
                $singles[] = [$item, $item->components()[0]];
            }
        }
        $products = $this->products->findByIds(array_map(static fn (array $single): Ulid => $single[1]->productId(), $singles));

        $references = [];
        foreach ($singles as [$item, $component]) {
            $product = $products[(string) $component->productId()] ?? null;
            if ($product instanceof Product) {
                $references[] = new ItemReference($item->externalRef(), $item->variation(), Sku::of($product->reference(), $component->variant()));
            }
        }

        return new PublishedReferences($description->label, \count($references), $connector->publishReferences($this->session->credentials($connection), $references));
    }
}
