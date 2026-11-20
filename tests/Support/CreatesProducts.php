<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Application\Product\CreateProduct\CreateProduct;
use App\Application\Product\CreateProduct\CreateProductHandler;
use App\Domain\Product\Product;
use App\Domain\Product\ProductRepository;
use App\Domain\Shared\Money;
use Doctrine\ORM\EntityManagerInterface;

trait CreatesProducts
{
    /**
     * @param list<string> $variants
     */
    protected static function createProduct(string $name, int $sellingPrice, int $cost = 0, array $variants = [], ?string $typeId = null, int $lowStockThreshold = Product::DEFAULT_LOW_STOCK_THRESHOLD, ?string $reference = null): string
    {
        $id = self::getContainer()->get(CreateProductHandler::class)(new CreateProduct($name, $sellingPrice, $variants, $typeId, $lowStockThreshold, $reference));
        if ($cost > 0) {
            self::getContainer()->get(ProductRepository::class)->get($id)->bought(Money::cents($cost));
            self::getContainer()->get(EntityManagerInterface::class)->flush();
        }

        return (string) $id;
    }
}
