<?php

declare(strict_types=1);

namespace App\Fixtures\Story;

use App\Domain\Design\Design;
use App\Domain\Design\DesignCollection;
use App\Domain\Design\Gabarit;
use App\Domain\Discount\ConditionSpec;
use App\Domain\Discount\DiscountAction;
use App\Domain\Event\Event;
use App\Domain\Identity\Workspace;
use App\Domain\Integration\ExternalItem;
use App\Domain\Integration\SalesContext;
use App\Domain\Integration\ServiceConnection;
use App\Domain\Integration\UnknownItems;
use App\Domain\Order\Order;
use App\Domain\Order\OrderedItem;
use App\Domain\Order\PaymentMethod;
use App\Domain\Product\Product;
use App\Domain\Product\ProductType;
use App\Domain\Purchasing\PurchasedItem;
use App\Domain\Purchasing\Supplier;
use App\Domain\Purchasing\SupplierOrder;
use App\Domain\Purchasing\SupplierOrderLine;
use App\Domain\Sales\SalesChannel;
use App\Domain\Shared\Money;
use App\Domain\Stock\LotOrigin;
use App\Domain\Stock\StockItem;
use App\Fixtures\Factory\DiscountRuleFactory;
use App\Fixtures\Factory\EventFactory;
use App\Fixtures\Factory\ProductFactory;
use App\Fixtures\Factory\ProductTypeFactory;
use App\Fixtures\Factory\UserFactory;
use App\Fixtures\Factory\WorkspaceFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Ulid;
use Zenstruck\Foundry\Story;

use function Zenstruck\Foundry\faker;

final class ProductionVolumeStory extends Story
{
    private const array TYPES = [
        ['Sticker', 'STI', 20, ['Mat', 'Brillant'], 400, 60],
        ['Print', 'PRI', 16, ['A5', 'A4', 'A3'], 1_500, 350],
        ['Carte postale', 'CAR', 15, ['Simple', 'Double'], 300, 50],
        ['T-shirt', 'TSH', 8, ['S', 'M', 'L', 'XL'], 2_500, 1_100],
        ['Tote bag', 'TOT', 10, ['Naturel', 'Noir'], 1_500, 600],
        ['Pin\'s', 'PIN', 5, [], 800, 250],
    ];

    private const int BEST_SELLERS = 6;
    private const int NEWCOMERS = 4;
    private const int NEVER_SOLD = 3;
    private const int NEWCOMER_DAYS = 25;

    private Workspace $workspace;

    private Product $sleeve;

    /** @var array<string, StockItem> */
    private array $stock = [];

    public function __construct(private readonly EntityManagerInterface $entityManager)
    {
    }

    public function build(): void
    {
        faker()->seed(75349);

        $this->workspace = WorkspaceFactory::createOne(['name' => 'Atelier Volume']);
        UserFactory::createOne(['accountId' => 'volume', 'email' => 'volume@mossytrunk.local', 'workspace' => $this->workspace]);
        $market = $this->entityManager->getRepository(SalesChannel::class)->findOneBy(['workspace' => $this->workspace, 'main' => true]) ?? throw new \LogicException('The workspace has no main channel.');
        $shop = $this->persisted(SalesChannel::open($this->workspace, 'Boutique en ligne'));

        $types = [];
        $catalogue = [];
        foreach (self::TYPES as $index => [$typeName, $code, $count, $variants, $selling, $buying]) {
            $types[$code] = ProductTypeFactory::createOne(['workspace' => $this->workspace, 'name' => $typeName, 'code' => $code, 'color' => ProductType::paletteColor($index)]);
            for ($i = 1; $i <= $count; ++$i) {
                $catalogue[] = $this->product($types[$code], \sprintf('%s-%03d', $code, $i), $variants, $selling, $buying);
            }
        }
        $ranked = $catalogue;
        array_multisort(array_map(static fn (Product $product): float => faker()->randomFloat(6), $ranked), $ranked);

        $packaging = ProductTypeFactory::createOne(['workspace' => $this->workspace, 'name' => 'Emballage', 'code' => 'EMB', 'color' => ProductType::paletteColor(6)]);
        $this->sleeve = $this->persisted(Product::supply($this->workspace, 'EMB-POCHETTE', 'Pochette', $packaging, ['Petite', 'Grande']));
        $this->sleeve->bought(Money::cents(5));
        $this->persisted(Product::supply($this->workspace, 'EMB-CARTON', 'Carton', $packaging))->bought(Money::cents(80));
        foreach ($this->sleeve->variants() as $variant) {
            $this->stockOf($this->sleeve, $variant)->receive(1_000, Money::cents(5_000), LotOrigin::Purchase, new \DateTimeImmutable('-120 days'));
        }
        $market->offerSupplies([$this->sleeve]);

        $this->rules($types);
        $this->entityManager->persist(ServiceConnection::create($this->workspace, 'sumup', ['api_key' => 'sup_volume'], SalesContext::AtEvent, UnknownItems::LinkByHand));

        $events = [
            EventFactory::new()->withExpenses(['Stand' => 18_000, 'Train' => 9_400])
                ->create(['workspace' => $this->workspace, 'name' => 'Japan Expo', 'location' => 'Paris', 'period' => EventFactory::during('-60 days', '-57 days')]),
            EventFactory::new()->withExpenses(['Stand' => 6_000, 'Essence' => 3_200])
                ->create(['workspace' => $this->workspace, 'name' => 'Festival de la BD', 'location' => 'Angoulême', 'period' => EventFactory::during('-20 days', '-18 days')]),
            EventFactory::new()->withExpenses(['Emplacement' => 1_500])
                ->create(['workspace' => $this->workspace, 'name' => 'Marché de quartier', 'location' => 'Lille', 'period' => EventFactory::during('-6 days', '-6 days')]),
        ];

        $baskets = [
            ...$this->basketsDuring($events[0], 220, $ranked),
            ...$this->basketsDuring($events[1], 129, $ranked),
            ...$this->basketsDuring($events[2], 20, $ranked),
            ...$this->basketsOutsideEvents(30, $ranked),
        ];
        $this->stockUp($ranked, $baskets);
        foreach ($baskets as ['event' => $event, 'at' => $placedAt, 'lines' => $lines]) {
            $this->place($event, $shop, $market, $placedAt, $lines);
        }

        $this->purchase(\array_slice($ranked, self::BEST_SELLERS + self::NEWCOMERS, -self::NEVER_SOLD));
        $this->designs($types, $catalogue);
        for ($i = 0; $i < 128; ++$i) {
            $this->entityManager->persist(ExternalItem::seen($this->workspace, 'sumup', (string) (900_000 + $i), \sprintf('Article %d', $i), [null, 'A4', 'M', 'Noir'][$i % 4], new \DateTimeImmutable('-30 days')));
        }

        $this->entityManager->flush();
    }

    /**
     * @param list<string> $variants
     */
    private function product(ProductType $type, string $reference, array $variants, int $selling, int $buying): Product
    {
        $selling = (int) round($selling * faker()->numberBetween(8, 15) / 10 / 50) * 50;
        $buying = (int) round($buying * faker()->numberBetween(7, 16) / 10 / 5) * 5;
        $product = ProductFactory::createOne([
            'workspace' => $this->workspace,
            'type' => $type,
            'reference' => $reference,
            'name' => ucfirst(faker()->unique()->word()),
            'sellingPrice' => Money::cents($selling),
            'variants' => $variants,
        ]);
        $product->bought(Money::cents($buying));

        return $product;
    }

    /**
     * @param array<string, ProductType> $types
     */
    private function rules(array $types): void
    {
        foreach (['STI' => 3, 'CAR' => 5, 'PRI' => 2, 'TOT' => 2] as $code => $quantity) {
            foreach ([1_000, 1_200, 1_500, 2_000] as $price) {
                DiscountRuleFactory::createOne([
                    'workspace' => $this->workspace,
                    'name' => \sprintf('%d %s pour %d €', $quantity, $types[$code]->name(), intdiv($price, 100)),
                    'conditions' => [ConditionSpec::on($quantity, $types[$code])],
                    'action' => DiscountAction::fixedPrice(Money::cents($price * $quantity / 2)),
                    'validity' => null,
                ]);
            }
        }
    }

    /**
     * @param list<Product> $ranked
     *
     * @return list<array{event: ?Event, at: \DateTimeImmutable, lines: list<array{Product, ?string, int}>}>
     */
    private function basketsDuring(Event $event, int $count, array $ranked): array
    {
        $days = (int) $event->period()->start()->diff($event->period()->end())->days;
        $baskets = [];
        for ($i = 0; $i < $count; ++$i) {
            $placedAt = $event->period()->start()
                ->modify(\sprintf('+%d days', faker()->numberBetween(0, $days)))
                ->setTime(faker()->numberBetween(10, 18), faker()->numberBetween(0, 59));
            $baskets[] = ['event' => $event, 'at' => $placedAt, 'lines' => $this->linesAt($placedAt, $ranked)];
        }

        return $baskets;
    }

    /**
     * @param list<Product> $ranked
     *
     * @return list<array{event: ?Event, at: \DateTimeImmutable, lines: list<array{Product, ?string, int}>}>
     */
    private function basketsOutsideEvents(int $count, array $ranked): array
    {
        $baskets = [];
        for ($i = 0; $i < $count; ++$i) {
            $placedAt = (new \DateTimeImmutable(\sprintf('-%d days', faker()->numberBetween(1, 90))))->setTime(faker()->numberBetween(10, 21), faker()->numberBetween(0, 59));
            $baskets[] = ['event' => null, 'at' => $placedAt, 'lines' => $this->linesAt($placedAt, $ranked)];
        }

        return $baskets;
    }

    /**
     * @param list<Product> $ranked
     *
     * @return list<array{Product, ?string, int}>
     */
    private function linesAt(\DateTimeImmutable $placedAt, array $ranked): array
    {
        $newcomersArrived = $placedAt >= new \DateTimeImmutable(\sprintf('-%d days', self::NEWCOMER_DAYS - 1));
        $pool = [];
        foreach (\array_slice($ranked, 0, -self::NEVER_SOLD, true) as $rank => $product) {
            $isNewcomer = $rank >= self::BEST_SELLERS && $rank < self::BEST_SELLERS + self::NEWCOMERS;
            if (!$isNewcomer || $newcomersArrived) {
                $pool[] = [$product, 1 / ($rank + 1) ** 0.8];
            }
        }

        $lines = [];
        foreach ($this->weightedPicks($pool, faker()->numberBetween(1, 3)) as $product) {
            $variant = $product->hasVariants() ? $product->variants()[faker()->numberBetween(0, \count($product->variants()) - 1)] : null;
            $lines[] = [$product, $variant, faker()->numberBetween(1, 2)];
        }

        return $lines;
    }

    /**
     * @param list<array{Product, float}> $pool
     *
     * @return list<Product>
     */
    private function weightedPicks(array $pool, int $count): array
    {
        $picked = [];
        while (\count($picked) < $count) {
            $target = faker()->randomFloat(6, 0, array_sum(array_column($pool, 1)));
            foreach ($pool as [$product, $weight]) {
                $target -= $weight;
                if ($target <= 0) {
                    $picked[$product->reference()] = $product;
                    break;
                }
            }
        }

        return array_values($picked);
    }

    /**
     * @param list<Product>                                                                                 $ranked
     * @param list<array{event: ?Event, at: \DateTimeImmutable, lines: list<array{Product, ?string, int}>}> $baskets
     */
    private function stockUp(array $ranked, array $baskets): void
    {
        $demand = [];
        foreach ($baskets as ['lines' => $lines]) {
            foreach ($lines as [$product, $variant, $quantity]) {
                $key = $product->reference().'|'.$variant;
                $demand[$key] = ($demand[$key] ?? 0) + $quantity;
            }
        }

        foreach ($ranked as $rank => $product) {
            $bestSeller = $rank < self::BEST_SELLERS;
            $newcomer = !$bestSeller && $rank < self::BEST_SELLERS + self::NEWCOMERS;
            $receivedAt = new \DateTimeImmutable($newcomer ? \sprintf('-%d days', self::NEWCOMER_DAYS) : '-120 days');
            foreach ([] === $product->variants() ? [null] : $product->variants() as $variant) {
                $needed = $demand[$product->reference().'|'.$variant] ?? 0;
                $quantity = $bestSeller ? $needed : $needed + faker()->numberBetween(10, 120);
                if ($quantity > 0) {
                    $this->stockOf($product, $variant)->receive($quantity, $product->buyingPrice()->multiply($quantity), LotOrigin::Purchase, $receivedAt);
                }
            }
        }
    }

    /**
     * @param list<array{Product, ?string, int}> $lines
     */
    private function place(?Event $event, SalesChannel $shop, SalesChannel $market, \DateTimeImmutable $placedAt, array $lines): void
    {
        $items = [];
        foreach ($lines as [$product, $variant, $quantity]) {
            $item = $product->sellable($variant);
            $items[] = (new OrderedItem($item, $quantity))->costing($this->stockOf($product, $variant)->withdraw($quantity, $item->buyingPrice));
        }
        $charged = array_reduce($items, static fn (Money $total, OrderedItem $ordered): Money => $total->add($ordered->item->sellingPrice->multiply($ordered->quantity)), Money::zero());
        $transaction = (string) new Ulid();
        $order = $this->persisted(Order::imported(self::reference('CMD', $placedAt), $this->workspace, 'sumup', $transaction, 'TX'.substr($transaction, -10), $event, $placedAt, $items, $charged, Money::zero(), PaymentMethod::Card, [], 'Remise', null === $event ? $shop : $market));
        $order->settleSaleFee('sumup', $transaction, $charged->percentage(175));
        if (null === $event) {
            return;
        }
        foreach ($this->sleeve->variants() as $size) {
            $supply = $this->sleeve->sellable($size);
            $order->useSupply($supply, 1, $this->stockOf($this->sleeve, $size)->withdraw(1, $supply->buyingPrice));
        }
    }

    /**
     * @param list<Product> $catalogue
     */
    private function purchase(array $catalogue): void
    {
        $suppliers = [
            $this->persisted(Supplier::create($this->workspace, 'Imprimerie du Lac', 'commandes@imprimerie-du-lac.test')),
            $this->persisted(Supplier::create($this->workspace, 'Atelier Textile', '04 78 00 00 00')),
        ];
        for ($i = 0; $i < 6; ++$i) {
            $orderedOn = new \DateTimeImmutable(\sprintf('-%d days', 90 - $i * 14));
            $purchased = [];
            $items = [];
            foreach (faker()->randomElements($catalogue, faker()->numberBetween(8, 16)) as $product) {
                if (!$product instanceof Product) {
                    continue;
                }
                $variant = $product->hasVariants() ? $product->variants()[0] : null;
                $purchased[] = [$product, $variant];
                $items[] = new PurchasedItem($product->sellable($variant), 20, $product->buyingPrice()->multiply(20));
            }
            $order = $this->persisted(SupplierOrder::place(self::reference('CMF', $orderedOn), $suppliers[$i % 2], $orderedOn, $items, null, Money::cents(1_200)));
            $receivedAt = $orderedOn->modify('+7 days');
            $lines = $order->lines();
            $order->receive(array_combine(array_map(static fn (SupplierOrderLine $line): string => (string) $line->id(), $lines), array_fill(0, \count($lines), 20)), $receivedAt);
            foreach ($lines as $index => $line) {
                [$product, $variant] = $purchased[$index];
                $this->stockOf($product, $variant)->receive((int) $line->receivedQuantity(), $line->landedCost(), LotOrigin::SupplierOrder, $receivedAt, $order->id());
            }
        }
    }

    /**
     * @param array<string, ProductType> $types
     * @param list<Product>              $catalogue
     */
    private function designs(array $types, array $catalogue): void
    {
        $gabarits = [
            $this->persisted(Gabarit::create($this->workspace, 'Sticker brillant', $types['STI'], Money::cents(400), [], ['Détourage', 'Contour de découpe'])),
            $this->persisted(Gabarit::create($this->workspace, 'Sticker mat', $types['STI'], Money::cents(400), [], ['Détourage'])),
            $this->persisted(Gabarit::create($this->workspace, 'Tirage A4', $types['PRI'], Money::cents(1_500), ['A5', 'A4', 'A3'], ['Fond perdu 3 mm', 'Profil CMJN'])),
            $this->persisted(Gabarit::create($this->workspace, 'Carte', $types['CAR'], Money::cents(300), [], ['Recadrage'])),
            $this->persisted(Gabarit::create($this->workspace, 'T-shirt', $types['TSH'], Money::cents(2_500), ['S', 'M', 'L', 'XL'], ['Vectorisation'])),
        ];
        $collections = array_map(fn (string $name): DesignCollection => $this->persisted(DesignCollection::start($this->workspace, $name)), ['Sous-bois', 'Océan', 'Montagne']);
        for ($i = 0; $i < 38; ++$i) {
            $collection = $collections[$i % 3];
            if ($i < 20) {
                $design = $this->persisted(Design::fromProduct($this->workspace, $catalogue[$i], $gabarits[$i % 2], $collection, new \DateTimeImmutable('-50 days')));
                $design->decline($gabarits[3]);
                continue;
            }
            $design = $this->persisted(Design::start($this->workspace, ucfirst(faker()->unique()->word()), $collection));
            $design->decline($gabarits[2]);
            $design->decline($gabarits[4]);
        }
    }

    /**
     * @template T of object
     *
     * @param T $entity
     *
     * @return T
     */
    private function persisted(object $entity): object
    {
        $this->entityManager->persist($entity);

        return $entity;
    }

    private function stockOf(Product $product, ?string $variant): StockItem
    {
        $key = $product->reference().'|'.$variant;
        if (!isset($this->stock[$key])) {
            $this->stock[$key] = StockItem::open($product, $variant);
            $this->entityManager->persist($this->stock[$key]);
        }

        return $this->stock[$key];
    }

    private static function reference(string $prefix, \DateTimeImmutable $at): string
    {
        return \sprintf('%s-%s-%s', $prefix, $at->format('Ymd'), substr((string) new Ulid(), -6));
    }
}
