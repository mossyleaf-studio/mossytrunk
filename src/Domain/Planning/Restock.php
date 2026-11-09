<?php

declare(strict_types=1);

namespace App\Domain\Planning;

use App\Domain\Event\Event;
use App\Domain\Identity\Workspace;
use App\Domain\Planning\Exception\RestockFromAnotherSupplier;
use App\Domain\Purchasing\LeadTime;
use App\Domain\Purchasing\Supplier;
use App\Domain\Purchasing\SupplierOrder;
use App\Domain\Shared\BusinessTime;
use App\Domain\Shared\OptionalText;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UlidType;
use Symfony\Component\Uid\Ulid;

#[ORM\Entity]
#[ORM\Table(name: 'restock')]
class Restock
{
    #[ORM\Id]
    #[ORM\Column(type: UlidType::NAME, unique: true)]
    private Ulid $id;

    #[ORM\ManyToOne(targetEntity: Workspace::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Workspace $workspace;

    #[ORM\ManyToOne(targetEntity: Supplier::class)]
    #[ORM\JoinColumn(nullable: false)]
    private Supplier $supplier;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $note = null;

    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $day = null;

    #[ORM\ManyToOne(targetEntity: Event::class)]
    #[ORM\JoinColumn(nullable: true)]
    private ?Event $event = null;

    #[ORM\Column]
    private int $leadDays;

    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $orderedOn = null;

    #[ORM\ManyToOne(targetEntity: SupplierOrder::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?SupplierOrder $supplierOrder = null;

    private function __construct(Supplier $supplier, ?string $note, ?\DateTimeImmutable $day, ?Event $event, int $leadDays)
    {
        $this->id = new Ulid();
        $this->workspace = $supplier->workspace();
        $this->revise($supplier, $note, $day, $event, $leadDays);
    }

    public static function plan(Supplier $supplier, ?string $note, ?\DateTimeImmutable $day, ?Event $event, int $leadDays): self
    {
        return new self($supplier, $note, $day, $event, $leadDays);
    }

    public function revise(Supplier $supplier, ?string $note, ?\DateTimeImmutable $day, ?Event $event, int $leadDays): void
    {
        if (null !== $this->supplierOrder && !self::sameSupplier($this->supplierOrder->supplier(), $supplier)) {
            throw new RestockFromAnotherSupplier($this->supplierOrder->supplier()->name());
        }

        $this->day = TargetDay::chosen($day, $event);
        $this->event = $event;
        $this->leadDays = LeadTime::days($leadDays);
        $this->supplier = $supplier;
        $this->note = OptionalText::of($note);
    }

    public function markOrdered(\DateTimeImmutable $on): void
    {
        $this->orderedOn = BusinessTime::midnightOf($on);
        $this->supplierOrder = null;
    }

    public function orderedThrough(SupplierOrder $order): void
    {
        if (!self::sameSupplier($order->supplier(), $this->supplier)) {
            throw new RestockFromAnotherSupplier($this->supplier->name());
        }

        $this->supplierOrder = $order;
        $this->orderedOn = $order->orderedOn();
    }

    public function followOrder(): void
    {
        if (null === $this->supplierOrder) {
            return;
        }

        $this->orderedOn = $this->supplierOrder->orderedOn();
        if (!self::sameSupplier($this->supplierOrder->supplier(), $this->supplier)) {
            $this->supplierOrder = null;
        }
    }

    public function markNotOrdered(): void
    {
        $this->orderedOn = null;
        $this->supplierOrder = null;
    }

    public function id(): Ulid
    {
        return $this->id;
    }

    public function workspace(): Workspace
    {
        return $this->workspace;
    }

    public function supplier(): Supplier
    {
        return $this->supplier;
    }

    public function note(): ?string
    {
        return $this->note;
    }

    public function day(): ?\DateTimeImmutable
    {
        return $this->day;
    }

    public function event(): ?Event
    {
        return $this->event;
    }

    public function leadDays(): int
    {
        return $this->leadDays;
    }

    public function orderedOn(): ?\DateTimeImmutable
    {
        return $this->orderedOn;
    }

    public function supplierOrder(): ?SupplierOrder
    {
        return $this->supplierOrder;
    }

    public function isOrdered(): bool
    {
        return null !== $this->orderedOn;
    }

    public function targetDay(): \DateTimeImmutable
    {
        return TargetDay::of($this->day, $this->event);
    }

    public function orderBy(): \DateTimeImmutable
    {
        return $this->targetDay()->modify(\sprintf('-%d days', $this->leadDays));
    }

    public function isPastOn(\DateTimeImmutable $today): bool
    {
        return BusinessTime::day($this->targetDay()) < BusinessTime::day($today);
    }

    public function stateOn(\DateTimeImmutable $today): StepState
    {
        return match (true) {
            $this->isOrdered() => StepState::Done,
            BusinessTime::day($this->orderBy()) < BusinessTime::day($today) => StepState::Late,
            default => StepState::Upcoming,
        };
    }

    private static function sameSupplier(Supplier $one, Supplier $other): bool
    {
        return $one->id()->equals($other->id());
    }
}
