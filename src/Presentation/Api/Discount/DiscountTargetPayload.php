<?php

declare(strict_types=1);

namespace App\Presentation\Api\Discount;

use App\Application\Discount\TargetDefinition;
use Symfony\Component\Validator\Constraints as Assert;

final readonly class DiscountTargetPayload
{
    public function __construct(
        #[Assert\Choice(choices: [TargetDefinition::PRODUCT, TargetDefinition::TYPE], message: 'discount.condition.kind.invalid')]
        public string $kind = TargetDefinition::PRODUCT,
        #[Assert\NotBlank(message: 'discount.condition.target.required')]
        #[Assert\Ulid(message: 'discount.condition.target.invalid')]
        public string $id = '',
        #[Assert\Length(max: 100)]
        public ?string $variant = null,
        #[Assert\Ulid(message: 'collection.invalid')]
        public ?string $collectionId = null,
    ) {
    }

    public function toDefinition(): TargetDefinition
    {
        return new TargetDefinition($this->kind, $this->id, $this->variant, $this->collectionId);
    }
}
