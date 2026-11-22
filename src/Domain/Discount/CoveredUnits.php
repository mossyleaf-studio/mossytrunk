<?php

declare(strict_types=1);

namespace App\Domain\Discount;

use Symfony\Component\Uid\Ulid;

final readonly class CoveredUnits
{
    public function __construct(
        public Ulid $productId,
        public ?string $variant,
        public int $quantity,
    ) {
    }

    public function covers(?Ulid $productId, ?string $variant): bool
    {
        return null !== $productId && $this->productId->equals($productId) && $this->variant === $variant;
    }

    public function movedTo(Ulid $productId, ?string $variant): self
    {
        return new self($productId, $variant, $this->quantity);
    }

    /**
     * @param list<array{productId: Ulid, variant: ?string}> $units
     *
     * @return list<self>
     */
    public static function tally(array $units): array
    {
        $tallied = [];
        foreach ($units as $unit) {
            $key = $unit['productId'].'|'.$unit['variant'];
            $tallied[$key] = new self($unit['productId'], $unit['variant'], ($tallied[$key]->quantity ?? 0) + 1);
        }

        return array_values($tallied);
    }

    /**
     * @return array{productId: string, variant: ?string, quantity: int}
     */
    public function toArray(): array
    {
        return ['productId' => (string) $this->productId, 'variant' => $this->variant, 'quantity' => $this->quantity];
    }

    /**
     * @param array{productId: string, variant: ?string, quantity: int} $data
     */
    public static function fromArray(array $data): self
    {
        return new self(Ulid::fromString($data['productId']), $data['variant'], $data['quantity']);
    }
}
