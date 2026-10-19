<?php

declare(strict_types=1);

namespace App\Domain\Integration;

use App\Domain\Identity\Workspace;
use App\Domain\Integration\Exception\EmptyItemLink;
use App\Domain\Product\SellableItem;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UlidType;
use Symfony\Component\Uid\Ulid;

#[ORM\Entity]
#[ORM\Table(name: 'external_item')]
#[ORM\UniqueConstraint(name: 'external_item_workspace_service_key', columns: ['workspace_id', 'service', 'item_key'])]
class ExternalItem
{
    #[ORM\Id]
    #[ORM\Column(type: UlidType::NAME, unique: true)]
    private Ulid $id;

    #[ORM\ManyToOne(targetEntity: Workspace::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Workspace $workspace;

    #[ORM\Column(length: 32)]
    private string $service;

    #[ORM\Column(length: 300)]
    private string $itemKey;

    #[ORM\Column(length: 255)]
    private string $externalRef;

    #[ORM\Column(length: 255)]
    private string $label;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $variation;

    /** @var Collection<int, ExternalItemComponent> */
    #[ORM\OneToMany(targetEntity: ExternalItemComponent::class, mappedBy: 'item', cascade: ['persist'], orphanRemoval: true)]
    #[ORM\OrderBy(['id' => 'ASC'])]
    private Collection $components;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
    private \DateTimeImmutable $seenAt;

    private function __construct(Workspace $workspace, string $service, string $externalRef, string $label, ?string $variation, \DateTimeImmutable $seenAt)
    {
        $this->id = new Ulid();
        $this->workspace = $workspace;
        $this->service = $service;
        $this->itemKey = self::keyOf($externalRef, $variation);
        $this->externalRef = mb_substr($externalRef, 0, 255);
        $this->label = mb_substr($label, 0, 255);
        $this->variation = null === $variation ? null : mb_substr($variation, 0, 255);
        $this->seenAt = $seenAt;
        $this->components = new ArrayCollection();
    }

    public static function seen(Workspace $workspace, string $service, string $externalRef, string $label, ?string $variation, \DateTimeImmutable $seenAt): self
    {
        return new self($workspace, $service, $externalRef, $label, $variation, $seenAt);
    }

    public static function keyOf(string $externalRef, ?string $variation): string
    {
        return mb_substr($externalRef.'|'.mb_strtolower(trim((string) $variation)), 0, 300);
    }

    public function seenAgain(string $label, \DateTimeImmutable $seenAt): void
    {
        $this->label = mb_substr($label, 0, 255);
        $this->seenAt = $seenAt;
    }

    public function link(SellableItem $item): void
    {
        $this->unlink();
        if (null !== $item->productId) {
            $this->components->add(new ExternalItemComponent($this, $item->productId, $item, 1));
        }
    }

    /**
     * @param list<array{SellableItem, int}> $components
     */
    public function linkAll(array $components): void
    {
        $known = [];
        foreach ($components as [$item, $quantity]) {
            if (null !== $item->productId) {
                $known[] = new ExternalItemComponent($this, $item->productId, $item, $quantity);
            }
        }
        if ([] === $known) {
            throw new EmptyItemLink();
        }

        $this->unlink();
        foreach ($known as $component) {
            $this->components->add($component);
        }
    }

    public function relink(Ulid $productId, ?string $variant, SellableItem $to): void
    {
        $moved = $this->components->findFirst(static fn (int $key, ExternalItemComponent $component): bool => $component->isOf($productId, $variant));
        if (null === $moved || null === $to->productId) {
            return;
        }

        $this->components->removeElement($moved);
        $this->components->add(new ExternalItemComponent($this, $to->productId, $to, $moved->quantity()));
    }

    public function unlink(): void
    {
        $this->components->clear();
    }

    public function isLinked(): bool
    {
        return !$this->components->isEmpty();
    }

    public function isSingle(): bool
    {
        $components = $this->components();

        return 1 === \count($components) && 1 === $components[0]->quantity();
    }

    /**
     * @return list<ExternalItemComponent>
     */
    public function components(): array
    {
        return array_values($this->components->toArray());
    }

    public function id(): Ulid
    {
        return $this->id;
    }

    public function service(): string
    {
        return $this->service;
    }

    public function itemKey(): string
    {
        return $this->itemKey;
    }

    public function externalRef(): string
    {
        return $this->externalRef;
    }

    public function label(): string
    {
        return $this->label;
    }

    public function variation(): ?string
    {
        return $this->variation;
    }

    public function seenAt(): \DateTimeImmutable
    {
        return $this->seenAt;
    }
}
