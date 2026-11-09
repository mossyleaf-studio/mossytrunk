<?php

declare(strict_types=1);

namespace App\Domain\Planning;

use App\Domain\Design\Design;
use App\Domain\Design\DesignCollection;
use App\Domain\Event\Event;
use App\Domain\Identity\Workspace;
use App\Domain\Planning\Exception\UnknownStep;
use App\Domain\Shared\BusinessTime;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UlidType;
use Symfony\Component\Uid\Ulid;

#[ORM\Entity]
#[ORM\Table(name: 'release_plan')]
class Release
{
    #[ORM\Id]
    #[ORM\Column(type: UlidType::NAME, unique: true)]
    private Ulid $id;

    #[ORM\ManyToOne(targetEntity: Workspace::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Workspace $workspace;

    #[ORM\ManyToOne(targetEntity: DesignCollection::class)]
    #[ORM\JoinColumn(unique: true, nullable: true, onDelete: 'CASCADE')]
    private ?DesignCollection $collection;

    #[ORM\ManyToOne(targetEntity: Design::class)]
    #[ORM\JoinColumn(unique: true, nullable: true, onDelete: 'CASCADE')]
    private ?Design $design;

    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $day = null;

    #[ORM\ManyToOne(targetEntity: Event::class)]
    #[ORM\JoinColumn(nullable: true)]
    private ?Event $event = null;

    /** @var list<array{label: string, days: int, done: bool}> */
    #[ORM\Column(type: Types::JSON)]
    private array $steps = [];

    /**
     * @param list<array{label: string, days: int, done?: bool}> $steps
     */
    private function __construct(Workspace $workspace, ?DesignCollection $collection, ?Design $design, ?\DateTimeImmutable $day, ?Event $event, array $steps)
    {
        $this->id = new Ulid();
        $this->workspace = $workspace;
        $this->collection = $collection;
        $this->design = $design;
        $this->schedule($day, $event);
        $this->replan($steps);
    }

    /**
     * @param list<array{label: string, days: int, done?: bool}> $steps
     */
    public static function ofCollection(DesignCollection $collection, ?\DateTimeImmutable $day, ?Event $event, array $steps): self
    {
        return new self($collection->workspace(), $collection, null, $day, $event, $steps);
    }

    /**
     * @param list<array{label: string, days: int, done?: bool}> $steps
     */
    public static function ofDesign(Design $design, ?\DateTimeImmutable $day, ?Event $event, array $steps): self
    {
        return new self($design->workspace(), null, $design, $day, $event, $steps);
    }

    public function schedule(?\DateTimeImmutable $day, ?Event $event): void
    {
        $this->day = TargetDay::chosen($day, $event);
        $this->event = $event;
    }

    /**
     * @param list<array{label: string, days: int, done?: bool}> $steps
     */
    public function replan(array $steps): void
    {
        $this->steps = StepList::clean($steps);
    }

    public function tick(string $label, bool $done): void
    {
        $index = array_search($label, array_column($this->steps, 'label'), true);
        if (false === $index) {
            throw new UnknownStep($label);
        }

        $this->steps[$index]['done'] = $done;
    }

    public function id(): Ulid
    {
        return $this->id;
    }

    public function workspace(): Workspace
    {
        return $this->workspace;
    }

    public function collection(): ?DesignCollection
    {
        return $this->collection;
    }

    public function design(): ?Design
    {
        return $this->design;
    }

    public function day(): ?\DateTimeImmutable
    {
        return $this->day;
    }

    public function event(): ?Event
    {
        return $this->event;
    }

    public function releaseDay(): \DateTimeImmutable
    {
        return TargetDay::of($this->day, $this->event);
    }

    public function isPastOn(\DateTimeImmutable $today): bool
    {
        return BusinessTime::day($this->releaseDay()) < BusinessTime::day($today);
    }

    /**
     * @return list<array{label: string, days: int, done: bool}>
     */
    public function steps(): array
    {
        return $this->steps;
    }

    /**
     * @return list<ScheduledStep>
     */
    public function scheduledSteps(int $workspaceReadyDaysBefore): array
    {
        return ReleaseSchedule::endingOn($this->lastStepDay($workspaceReadyDaysBefore), $this->steps);
    }

    private function lastStepDay(int $workspaceReadyDaysBefore): \DateTimeImmutable
    {
        return $this->event?->readyBy($workspaceReadyDaysBefore) ?? $this->releaseDay()->modify('-1 day');
    }
}
