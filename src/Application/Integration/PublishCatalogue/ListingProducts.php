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

    /**
     * @return list<list<string>>
     */
    public function linesOf(DescribedListing $listing): array
    {
        return array_map($this->unitsOf(...), $listing->lines);
    }

    /**
     * @return list<string>
     */
    private function unitsOf(ExternalLine $line): array
    {
        $item = $this->remembered[ExternalItem::keyOf($line->externalRef, OptionalText::of($line->variant))] ?? null;
        if (null !== $item && $item->isLinked()) {
            return array_merge(...array_map(static fn (ExternalItemComponent $component): array => array_fill(0, max(1, $component->quantity()), (string) $component->productId()), $item->components()));
        }
        $product = null === $line->sku ? null : $this->catalogue->withReference($line->sku) ?? $this->catalogue->withVariantSku($line->sku)[0] ?? null;

        return null === $product ? [] : [(string) $product->id()];
    }
}
