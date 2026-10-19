<?php

declare(strict_types=1);

namespace App\Application\Integration\ListExternalItems;

use App\Domain\Integration\ExternalItem;
use App\Domain\Integration\ExternalItemRepository;
use App\Domain\Product\Product;
use App\Domain\Product\ProductRepository;

final readonly class ListExternalItemsHandler
{
    public function __construct(
        private ExternalItemRepository $items,
        private ProductRepository $products,
    ) {
    }

    /**
     * @return list<ExternalItemView>
     */
    public function __invoke(string $service): array
    {
        $products = [];
        foreach ($this->products->articles() as $product) {
            $products[(string) $product->id()] = $product;
        }

        $views = array_map(static function (ExternalItem $item) use ($products): ExternalItemView {
            $components = [];
            foreach ($item->components() as $component) {
                $product = $products[(string) $component->productId()] ?? null;
                if ($product instanceof Product) {
                    $components[] = ['productId' => (string) $product->id(), 'name' => $product->displayName(), 'variant' => $component->variant(), 'quantity' => $component->quantity()];
                }
            }

            return new ExternalItemView(
                (string) $item->id(),
                $item->externalRef(),
                $item->label(),
                $item->variation(),
                $components,
                $item->seenAt()->format(\DateTimeInterface::ATOM),
            );
        }, $this->items->ofService($service));

        usort($views, static fn (ExternalItemView $a, ExternalItemView $b): int => [[] !== $a->components, $b->seenAt] <=> [[] !== $b->components, $a->seenAt]);

        return $views;
    }
}
