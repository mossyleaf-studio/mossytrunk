<?php

declare(strict_types=1);

namespace App\Domain\Event;

use App\Domain\Shared\Exception\NonPositiveAmount;
use App\Domain\Shared\Money;
use App\Domain\Shared\OptionalText;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UlidType;
use Symfony\Component\Uid\Ulid;

#[ORM\Entity]
#[ORM\Table(name: 'event_donation')]
class Donation
{
    #[ORM\Id]
    #[ORM\Column(type: UlidType::NAME, unique: true)]
    private Ulid $id;

    #[ORM\ManyToOne(targetEntity: Event::class, inversedBy: 'donations')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Event $event;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $label;

    #[ORM\Embedded(class: Money::class, columnPrefix: 'amount_')]
    private Money $amount;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    public function __construct(Event $event, ?string $label, Money $amount)
    {
        if (!$amount->isPositive()) {
            throw new NonPositiveAmount('donation_amount');
        }
        $this->id = new Ulid();
        $this->event = $event;
        $this->label = OptionalText::of($label);
        $this->amount = $amount;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function id(): Ulid
    {
        return $this->id;
    }

    public function label(): ?string
    {
        return $this->label;
    }

    public function amount(): Money
    {
        return $this->amount;
    }
}
