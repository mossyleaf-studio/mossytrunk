<?php

declare(strict_types=1);

namespace App\Application\Integration\ImportSales;

use App\Application\Product\CreateProductType\MiscellaneousType;
use App\Application\Product\CreateProductType\ProductTypeCreator;
use App\Application\Product\NewProducts;
use App\Domain\Product\Product;
use App\Domain\Product\ProductRepository;
use App\Domain\Product\ProductType;
use App\Domain\Product\ProductTypeRepository;
use App\Domain\Product\Sku;
use App\Domain\Shared\Money;
use Symfony\Component\Uid\Ulid;

final class ImportedCatalogue
{
    private bool $loaded = false;

    /** @var array<string, Product> */
    private array $byDisplayName = [];

    /** @var array<string, Product> */
    private array $byTypeAndName = [];

    /** @var array<string, Product|null> */
    private array $byOwnName = [];

    /** @var array<string, Product|null> */
    private array $byReference = [];

    /** @var array<string, Product> */
    private array $created = [];

    /** @var array<string, ProductType> */
    private array $types = [];

    private int $typesCreated = 0;

    private ?ProductType $miscellaneousType = null;

    public function __construct(
        private readonly ProductRepository $products,
        private readonly NewProducts $newProducts,
        private readonly ProductTypeRepository $typeRepository,
        private readonly ProductTypeCreator $typeCreator,
        private readonly MiscellaneousType $miscellaneous,
    ) {
    }

    public function named(string $displayName, ?string $category = null): ?Product
    {
        $this->load();
        $product = $this->byDisplayName[mb_strtolower(trim($displayName))] ?? null;
        if (null !== $product || null === $category || '' === trim($category)) {
            return $product;
        }

        return $this->byTypeAndName[self::typedKey(trim($category), self::withoutPrefix(trim($displayName), trim($category)))] ?? null;
    }

    public function namedWithoutType(string $name): ?Product
    {
        $this->load();

        return $this->byOwnName[mb_strtolower(trim($name))] ?? null;
    }

    public function withReference(string $reference): ?Product
    {
        $reference = trim($reference);
        if ('' === $reference) {
            return null;
        }
        if (!\array_key_exists($reference, $this->byReference)) {
            $product = $this->products->findByReference($reference);
            $this->byReference[$reference] = true === $product?->isSupply() ? null : $product;
        }

        return $this->byReference[$reference];
    }

    /**
     * @return array{Product, string}|null
     */
    public function withVariantSku(string $sku): ?array
    {
        for ($dash = mb_strrpos($sku, Sku::SEPARATOR); false !== $dash && $dash > 0; $dash = mb_strrpos(mb_substr($sku, 0, $dash), Sku::SEPARATOR)) {
            $product = $this->withReference(mb_substr($sku, 0, $dash));
            $known = $product?->variantNamed(mb_substr($sku, $dash + 1));
            if (null !== $product && null !== $known) {
                return [$product, $known];
            }
        }

        return null;
    }

    public function withId(Ulid $id): ?Product
    {
        return $this->products->find($id);
    }

    public function wasCreated(Product $product): bool
    {
        return isset($this->created[(string) $product->id()]);
    }

    public function createFor(string $name, ?string $category, Money $sellingPrice): Product
    {
        if (null === $category || '' === trim($category)) {
            return $this->create($name, $sellingPrice, $this->miscellaneousType ??= $this->miscellaneous->get());
        }
        $type = $this->type(trim($category));

        return $this->create(self::withoutPrefix($name, $type->name()), $sellingPrice, $type);
    }

    public function createdCount(): int
    {
        return \count($this->created);
    }

    public function typesCreatedCount(): int
    {
        return $this->typesCreated;
    }

    private function create(string $name, Money $sellingPrice, ProductType $type): Product
    {
        return $this->created($this->newProducts->create($name, $sellingPrice, $type));
    }

    private function created(Product $product): Product
    {
        $this->load();
        $this->byDisplayName[mb_strtolower($product->displayName())] = $product;
        $this->byTypeAndName[self::typedKey($product->type()->name(), $product->name())] ??= $product;
        $this->byOwnName = self::withOwnName($this->byOwnName, $product);
        $this->created[(string) $product->id()] = $product;

        return $product;
    }

    private function load(): void
    {
        if ($this->loaded) {
            return;
        }
        $this->loaded = true;
        foreach ($this->products->articles() as $product) {
            $this->byDisplayName[mb_strtolower($product->displayName())] ??= $product;
            $this->byTypeAndName[self::typedKey($product->type()->name(), $product->name())] ??= $product;
            $this->byOwnName = self::withOwnName($this->byOwnName, $product);
        }
    }

    /**
     * @param array<string, Product|null> $index
     *
     * @return array<string, Product|null>
     */
    private static function withOwnName(array $index, Product $product): array
    {
        $key = mb_strtolower(trim($product->name()));
        $index[$key] = \array_key_exists($key, $index) && $index[$key] !== $product ? null : $product;

        return $index;
    }

    private static function typedKey(string $typeName, string $name): string
    {
        return mb_strtolower(trim($typeName))."\n".mb_strtolower(trim($name));
    }

    private function type(string $name): ProductType
    {
        $key = mb_strtolower($name);
        if (!isset($this->types[$key])) {
            $existing = $this->typeRepository->findByName($name);
            if (null === $existing) {
                $existing = $this->typeCreator->create($name);
                ++$this->typesCreated;
            }
            $this->types[$key] = $existing;
        }

        return $this->types[$key];
    }

    private static function withoutPrefix(string $name, string $typeName): string
    {
        $prefix = $typeName.' ';
        if (0 === mb_stripos($name, $prefix) && '' !== trim(mb_substr($name, mb_strlen($prefix)))) {
            return trim(mb_substr($name, mb_strlen($prefix)));
        }

        return $name;
    }
}
