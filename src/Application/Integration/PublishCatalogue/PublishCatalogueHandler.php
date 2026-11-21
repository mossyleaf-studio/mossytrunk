<?php

declare(strict_types=1);

namespace App\Application\Integration\PublishCatalogue;

use App\Application\Integration\AddedConnection;
use App\Application\Integration\ConnectionSession;
use App\Application\Integration\Connectors;
use App\Application\Integration\Exception\EmptyCatalogue;
use App\Application\Integration\StockedItem;
use App\Application\Integration\StockedProduct;
use App\Domain\Product\Product;
use App\Domain\Product\ProductRepository;
use App\Domain\Product\Sku;
use App\Domain\Sales\SalesChannel;
use App\Domain\Sales\SalesChannelRepository;
use App\Domain\Stock\StockItem;
use App\Domain\Stock\StockRepository;

final readonly class PublishCatalogueHandler
{
    public function __construct(
        private Connectors $connectors,
        private AddedConnection $addedConnections,
        private ConnectionSession $session,
        private ProductRepository $products,
        private SalesChannelRepository $channels,
        private StockRepository $stock,
        private ProductDescriptions $descriptions,
    ) {
    }

    public function __invoke(string $service): PublishedCatalogue
    {
        $connector = $this->connectors->cataloguePublishing($service);
        $label = $connector->describe()->label;
        $credentials = $this->session->credentials($this->addedConnections->of($connector));

        $products = $this->stocked($this->channels->linkedTo($service));
        if ([] === $products) {
            throw new EmptyCatalogue($label);
        }
        $described = $this->descriptions->of($products);
        $changes = $connector->publishCatalogue($credentials, $described->products);

        return new PublishedCatalogue($label, \count($products), $changes->created, $changes->updated, $changes->retired, $described->reports);
    }

    /**
     * @return list<StockedProduct>
     */
    private function stocked(?SalesChannel $channel): array
    {
        $stock = $this->stock->byProduct();
        $stocked = [];
        foreach ($this->products->articles() as $product) {
            $items = $product->isArchived() ? [] : self::items($product, $stock[(string) $product->id()] ?? []);
            if ([] !== $items) {
                $stocked[] = new StockedProduct((string) $product->id(), $product->reference(), $product->name(), $product->type()->name(), $product->displayName(), $product->priceOn($channel), $items);
            }
        }

        return $stocked;
    }

    /**
     * @param list<StockItem> $stock
     *
     * @return list<StockedItem>
     */
    private static function items(Product $product, array $stock): array
    {
        $variants = $product->hasVariants() ? $product->activeVariants() : [null];

        return array_map(
            static fn (?string $variant): StockedItem => new StockedItem($variant, Sku::of($product->reference(), $variant), self::available($stock, $variant)),
            $variants,
        );
    }

    /**
     * @param list<StockItem> $stock
     */
    private static function available(array $stock, ?string $variant): int
    {
        $item = array_find($stock, static fn (StockItem $item): bool => $item->variant() === $variant);

        return max(0, $item?->onHand() ?? 0);
    }
}
