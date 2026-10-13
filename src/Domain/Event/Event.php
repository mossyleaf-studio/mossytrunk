<?php

declare(strict_types=1);

namespace App\Domain\Event;

use App\Domain\Event\Exception\EmptyEventLocation;
use App\Domain\Event\Exception\EmptyEventName;
use App\Domain\Event\Exception\SharingEndsBeforeEvent;
use App\Domain\Event\Exception\UnknownDonation;
use App\Domain\Event\Exception\UnknownExpense;
use App\Domain\Identity\Workspace;
use App\Domain\Shared\DateRange;
use App\Domain\Shared\Money;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UlidType;
use Symfony\Component\Uid\Ulid;

#[ORM\Entity]
#[ORM\Table(name: 'event')]
class Event
{
    #[ORM\Id]
    #[ORM\Column(type: UlidType::NAME, unique: true)]
    private Ulid $id;

    #[ORM\ManyToOne(targetEntity: Workspace::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Workspace $workspace;

    #[ORM\Column(length: 255)]
    private string $name;

    #[ORM\Column(length: 255)]
    private string $location;

    #[ORM\Embedded(class: DateRange::class, columnPrefix: 'period_')]
    private DateRange $period;

    /** @var Collection<int, Expense> */
    #[ORM\OneToMany(targetEntity: Expense::class, mappedBy: 'event', cascade: ['persist'], orphanRemoval: true)]
    #[ORM\OrderBy(['createdAt' => 'ASC'])]
    private Collection $expenses;

    /** @var Collection<int, Donation> */
    #[ORM\OneToMany(targetEntity: Donation::class, mappedBy: 'event', cascade: ['persist'], orphanRemoval: true)]
    #[ORM\OrderBy(['createdAt' => 'ASC'])]
    private Collection $donations;

    private function __construct(Ulid $id, Workspace $workspace, string $name, string $location, DateRange $period)
    {
        $this->id = $id;
        $this->workspace = $workspace;
        $this->expenses = new ArrayCollection();
        $this->donations = new ArrayCollection();
        $this->describe($name, $location);
        $this->period = $period;
    }

    public static function schedule(Workspace $workspace, string $name, string $location, DateRange $period): self
    {
        return new self(new Ulid(), $workspace, $name, $location, $period);
    }

    public function describe(string $name, string $location): void
    {
        $name = trim($name);
        $location = trim($location);

        if ('' === $name) {
            throw new EmptyEventName();
        }
        if ('' === $location) {
            throw new EmptyEventLocation();
        }

        $this->name = $name;
        $this->location = $location;
    }

    public function reschedule(DateRange $period): void
    {
        $this->period = $period;
    }

    public function addExpense(string $label, Money $amount, ?ExpenseSpread $spread = null): Expense
    {
        $expense = new Expense($this, $label, $amount, $this->fitting($spread ?? ExpenseSpread::none()));
        $this->expenses->add($expense);

        return $expense;
    }

    public function reviseExpense(Ulid $expenseId, string $label, Money $amount, ?ExpenseSpread $spread = null): void
    {
        $this->expense($expenseId)->revise($label, $amount, $this->fitting($spread ?? ExpenseSpread::none()));
    }

    private function fitting(ExpenseSpread $spread): ExpenseSpread
    {
        if ($spread->endsBefore($this->period->start())) {
            throw new SharingEndsBeforeEvent();
        }

        return $spread;
    }

    public function removeExpense(Ulid $expenseId): void
    {
        $this->expenses->removeElement($this->expense($expenseId));
    }

    private function expense(Ulid $expenseId): Expense
    {
        return $this->expenses->findFirst(static fn (int $key, Expense $expense): bool => $expense->id()->equals($expenseId))
            ?? throw new UnknownExpense((string) $expenseId);
    }

    public function receiveDonation(?string $label, Money $amount): Donation
    {
        $donation = new Donation($this, $label, $amount);
        $this->donations->add($donation);

        return $donation;
    }

    public function removeDonation(Ulid $donationId): void
    {
        $this->donations->removeElement(
            $this->donations->findFirst(static fn (int $key, Donation $donation): bool => $donation->id()->equals($donationId))
                ?? throw new UnknownDonation((string) $donationId),
        );
    }

    /**
     * @return list<Donation>
     */
    public function donations(): array
    {
        return array_values($this->donations->toArray());
    }

    public function donationsTotal(): Money
    {
        return Money::sum(array_map(static fn (Donation $donation): Money => $donation->amount(), $this->donations()));
    }

    public function covers(\DateTimeImmutable $moment): bool
    {
        return $this->period->covers($moment);
    }

    public function timingOn(\DateTimeImmutable $today): EventTiming
    {
        return match (true) {
            $this->period->isAfter($today) => EventTiming::Upcoming,
            $this->period->isBefore($today) => EventTiming::Past,
            default => EventTiming::Ongoing,
        };
    }

    public function id(): Ulid
    {
        return $this->id;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function location(): string
    {
        return $this->location;
    }

    public function period(): DateRange
    {
        return $this->period;
    }

    public function startsIn(int $year): bool
    {
        return DateRange::yearOf($this->period->start()) === $year;
    }

    /**
     * @return list<Expense>
     */
    public function expenses(): array
    {
        return array_values($this->expenses->toArray());
    }

    public function workspace(): Workspace
    {
        return $this->workspace;
    }
}
