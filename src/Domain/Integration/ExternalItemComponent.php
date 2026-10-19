<?php

declare(strict_types=1);

namespace App\Domain\Integration;

use App\Domain\Integration\Exception\NonPositiveLinkedQuantity;
use App\Domain\Product\SellableItem;
use App\Domain\Product\VariantLabel;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UlidType;
use Symfony\Component\Uid\Ulid;

#[ORM\Entity]
#[ORM\Table(name: 'external_item_component')]
#[ORM\Index(name: 'external_item_component_product_idx', columns: ['product_id'])]
class ExternalItemComponent
{
    #[ORM\Id]
    #[ORM\Column(type: UlidType::NAME, unique: true)]
    private Ulid $id;

    #[ORM\ManyToOne(targetEntity: ExternalItem::class, inversedBy: 'components')]
    #[ORM\JoinColumn(name: 'external_item_id', nullable: false, onDelete: 'CASCADE')]
    private ExternalItem $item;

    #[ORM\Column(type: UlidType::NAME)]
    private Ulid $productId;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $variant;

    #[ORM\Column]
    private int $quantity;

    public function __construct(ExternalItem $item, Ulid $productId, SellableItem $sellable, int $quantity)
    {
        if ($quantity < 1) {
            throw new NonPositiveLinkedQuantity($sellable->label());
        }
        $this->id = new Ulid();
        $this->item = $item;
        $this->productId = $productId;
        $this->variant = $sellable->variant;
        $this->quantity = $quantity;
    }

    public function isOf(Ulid $productId, ?string $variant): bool
    {
        return $this->productId->equals($productId) && VariantLabel::same($this->variant, $variant);
    }

    public function productId(): Ulid
    {
        return $this->productId;
    }

    public function variant(): ?string
    {
        return $this->variant;
    }

    public function quantity(): int
    {
        return $this->quantity;
    }
}
