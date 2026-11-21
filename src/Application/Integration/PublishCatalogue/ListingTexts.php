<?php

declare(strict_types=1);

namespace App\Application\Integration\PublishCatalogue;

use App\Application\Integration\DescribedListing;

final readonly class ListingTexts
{
    /**
     * @param array<string, array<string, string>> $byProduct
     * @param array<string, true>                  $reached
     */
    private function __construct(
        private bool $read,
        private array $byProduct = [],
        private array $reached = [],
        private int $listings = 0,
        private int $withoutText = 0,
        private int $unlinked = 0,
        private int $bundles = 0,
    ) {
    }

    public static function unread(): self
    {
        return new self(false);
    }

    /**
     * @param list<DescribedListing> $listings
     */
    public static function read(array $listings, ListingProducts $products): self
    {
        usort($listings, static fn (DescribedListing $a, DescribedListing $b): int => strnatcmp($a->externalRef, $b->externalRef));
        $own = [];
        $shared = [];
        $reached = [];
        $unlinked = 0;
        $bundles = 0;
        foreach ($listings as $listing) {
            $lines = $products->linesOf($listing);
            $single = self::singleProducts($lines);
            $unlinked += [] === array_merge([], ...$lines) ? 1 : 0;
            $bundles += [] === array_filter($lines, static fn (array $units): bool => \count($units) > 1) ? 0 : 1;
            $reached += array_fill_keys($single, true);
            $texts = [] === $listing->descriptions ? [] : array_fill_keys($single, $listing->descriptions);
            if (self::isOwn($lines, $single)) {
                $own += $texts;
            } else {
                $shared += $texts;
            }
        }

        return new self(true, $own + $shared, $reached, \count($listings), \count(array_filter($listings, static fn (DescribedListing $listing): bool => [] === $listing->descriptions)), $unlinked, $bundles);
    }

    /**
     * @param list<list<string>> $lines
     * @param list<string>       $single
     */
    private static function isOwn(array $lines, array $single): bool
    {
        return 1 === \count($single) && \count($lines) === \count(array_filter($lines, static fn (array $units): bool => $units === $single));
    }

    /**
     * @param list<list<string>> $lines
     *
     * @return list<string>
     */
    private static function singleProducts(array $lines): array
    {
        $single = [];
        foreach ($lines as $units) {
            if (1 === \count($units)) {
                $single[$units[0]] = true;
            }
        }

        return array_map('strval', array_keys($single));
    }

    /**
     * @return array<string, string>
     */
    public function of(string $productId): array
    {
        return $this->byProduct[$productId] ?? [];
    }

    public function reaches(string $productId): bool
    {
        return isset($this->reached[$productId]);
    }

    public function report(string $label, int $described, int $notReached): DescriptionReport
    {
        return new DescriptionReport($label, $this->read, $this->listings, $described, $this->read ? $notReached : 0, $this->withoutText, $this->unlinked, $this->bundles);
    }
}
