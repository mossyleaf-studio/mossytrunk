<?php

declare(strict_types=1);

namespace App\Application\Integration\LinkExternalItem;

use App\Application\Order\RequestedLine;
use App\Application\Transaction;
use App\Domain\Integration\ExternalItemRepository;
use App\Domain\Product\ProductRepository;
use App\Domain\Shared\Exception\NotFound;
use Symfony\Component\Uid\Ulid;

final readonly class LinkExternalItemHandler
{
    public function __construct(
        private ExternalItemRepository $items,
        private ProductRepository $products,
        private Transaction $transaction,
    ) {
    }

    /**
     * @param list<RequestedLine> $lines
     */
    public function __invoke(string $service, string $itemId, array $lines): void
    {
        $item = $this->items->get(Ulid::fromString($itemId));
        if ($item->service() !== $service) {
            throw new NotFound('external_item', $itemId);
        }

        $item->linkAll(array_map(fn (RequestedLine $line): array => [$this->products->get(Ulid::fromString($line->productId))->sellable($line->variant), $line->quantity], $lines));
        $this->transaction->commit();
    }
}
