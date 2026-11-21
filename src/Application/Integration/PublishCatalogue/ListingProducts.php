<?php

declare(strict_types=1);

namespace App\Application\Integration\PublishCatalogue;

use App\Application\Integration\DescribedListing;
use App\Application\Integration\ExternalLine;
use App\Application\Integration\ImportSales\ImportedCatalogue;
use App\Domain\Integration\ExternalItem;
use App\Domain\Integration\ExternalItemComponent;
use App\Domain\Shared\OptionalText;

final readonly class ListingProducts
{
    /** @var array<string, ExternalItem> */
    private array $remembered;

    /**
     * @param list<ExternalItem> $remembered
     */
    public function __construct(array $remembered, private ImportedCatalogue $catalogue)
    {
        $byKey = [];
        foreach ($remembered as $item) {
            $byKey[$item->itemKey()] = $item;
        }
        $this->remembered = $byKey;
    }

    public function productOf(DescribedListing $listing): ?string
    {
        $products = [];
        foreach ($listing->lines as $line) {
            foreach ($this->productsOf($line) as $product) {
                $products[$product] = true;
            }
        }

        return 1 === \count($products) ? (string) array_key_first($products) : null;
    }

    /**
     * @return list<string>
     */
    private function productsOf(ExternalLine $line): array
    {
        $item = $this->remembered[ExternalItem::keyOf($line->externalRef, OptionalText::of($line->variant))] ?? null;
        if (null !== $item && $item->isLinked()) {
            return array_map(static fn (ExternalItemComponent $component): string => (string) $component->productId(), $item->components());
        }
        $product = null === $line->sku ? null : $this->catalogue->withReference($line->sku) ?? $this->catalogue->withVariantSku($line->sku)[0] ?? null;

        return null === $product ? [] : [(string) $product->id()];
    }
}
