<?php

declare(strict_types=1);

namespace App\Domain\Planning;

use App\Domain\Identity\Workspace;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UlidType;
use Symfony\Component\Uid\Ulid;

#[ORM\Entity]
#[ORM\Table(name: 'release_template')]
class ReleaseTemplate
{
    #[ORM\Id]
    #[ORM\Column(type: UlidType::NAME, unique: true)]
    private Ulid $id;

    #[ORM\ManyToOne(targetEntity: Workspace::class)]
    #[ORM\JoinColumn(unique: true, nullable: false, onDelete: 'CASCADE')]
    private Workspace $workspace;

    /** @var list<array{label: string, days: int}> */
    #[ORM\Column(type: Types::JSON)]
    private array $steps = [];

    private function __construct(Workspace $workspace)
    {
        $this->id = new Ulid();
        $this->workspace = $workspace;
    }

    public static function of(Workspace $workspace): self
    {
        return new self($workspace);
    }

    /**
     * @param list<array{label: string, days: int}> $steps
     */
    public function define(array $steps): void
    {
        $this->steps = array_map(static fn (array $step): array => ['label' => $step['label'], 'days' => $step['days']], StepList::clean($steps));
    }

    public function id(): Ulid
    {
        return $this->id;
    }

    public function workspace(): Workspace
    {
        return $this->workspace;
    }

    /**
     * @return list<array{label: string, days: int}>
     */
    public function steps(): array
    {
        return $this->steps;
    }
}
