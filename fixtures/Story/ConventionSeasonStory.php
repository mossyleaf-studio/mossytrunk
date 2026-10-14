<?php

declare(strict_types=1);

namespace App\Fixtures\Story;

use App\Domain\Design\Design;
use App\Domain\Design\DesignCollection;
use App\Domain\Design\Gabarit;
use App\Domain\Discount\BasketLine;
use App\Domain\Discount\ConditionSpec;
use App\Domain\Discount\DiscountAction;
use App\Domain\Discount\DiscountCalculator;
use App\Domain\Discount\DiscountRule;
use App\Domain\Discount\TargetSpec;
use App\Domain\Discount\ValidityPeriod;
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
use App\Domain\Sales\ChannelCostKind;
use App\Domain\Sales\PriceAdjustment;
use App\Domain\Sales\SalesChannel;
use App\Domain\Shared\Money;
use App\Domain\Stock\LotOrigin;
use App\Domain\Stock\StockCheck;
use App\Domain\Stock\StockCount;
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

final class ConventionSeasonStory extends Story
{
    private Workspace $workspace;

    private SalesChannel $market;

    private Product $sleeve;
    private Product $envelope;

    /** @var array<string, StockItem> */
    private array $stock = [];

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly DiscountCalculator $discountCalculator,
    ) {
    }

    public function build(): void
    {
        faker()->seed(2026);

        $workspace = WorkspaceFactory::createOne(['name' => 'Atelier Mousse']);
        UserFactory::createOne(['accountId' => 'demo', 'email' => 'demo@mossytrunk.local', 'workspace' => $workspace]);
        $this->workspace = $workspace;
        $this->market = $this->entityManager->getRepository(SalesChannel::class)->findOneBy(['workspace' => $workspace, 'main' => true]) ?? throw new \LogicException('The workspace has no main channel.');

        $colorIndex = 0;
        $type = static function (string $name, string $code) use ($workspace, &$colorIndex): ProductType {
            return ProductTypeFactory::createOne(['workspace' => $workspace, 'name' => $name, 'code' => $code, 'color' => ProductType::paletteColor($colorIndex++)]);
        };
        $sticker = $type('Sticker', 'STI');
        $print = $type('Print', 'PRI');

        $stickers = [
            $this->product('STI-MOUSSE', 'Mousse', 400, 60, type: $sticker),
            $this->product('STI-FOUGERE', 'Fougère', 400, 60, type: $sticker),
            $this->product('STI-CHAMPIGNON', 'Champignon', 400, 60, type: $sticker),
            $this->product('STI-HOLO', 'Holographique', 600, 120, type: $sticker),
        ];
        $prints = [
            $this->product('PRI-FORET', 'Forêt', 1_500, 350, ['A5', 'A4', 'A3'], $print),
            $this->product('PRI-RIVIERE', 'Rivière', 1_500, 350, ['A5', 'A4', 'A3'], $print),
        ];
        $others = [
            $this->product('TSH-LICHEN', 'Lichen', 2_500, 1_100, ['S', 'M', 'L', 'XL'], $type('T-shirt', 'TSH')),
            $this->product('TOT-BAG', 'Mousse', 1_500, 600, ['Naturel', 'Noir'], $type('Tote bag', 'TOT')),
            $this->product('PIN-ESCARGOT', 'Escargot', 800, 250, type: $type('Pin\'s', 'PIN')),
            $this->product('ZIN-SOUS-BOIS', 'Sous-bois', 1_000, 0, type: $type('Zine', 'ZIN')), // buying price unknown on purpose
            $this->product('ORI-CLAIRIERE', '« Clairière »', 12_000, 0, type: $type('Aquarelle originale', 'AQU')), // unique product
        ];
        $catalogue = [...$stickers, ...$prints, ...$others];

        $packaging = $type('Emballage', 'EMB');
        $packaging->prefixNames(false);
        $stationery = $type('Papeterie', 'PAP');
        $stationery->prefixNames(false);
        $this->sleeve = $this->supply('EMB-POCHETTE', 'Pochette', 5, ['Petite', 'Grande'], $packaging);
        $this->envelope = $this->supply('EMB-ENVELOPPE', 'Enveloppe kraft', 25, [], $packaging);
        $flyer = $this->supply('PAP-FLYER', 'Flyer', 8, [], $stationery);
        $this->market->offerSupplies([$this->sleeve]);

        $rule = static fn (string $name, array $conditions, DiscountAction $action, ?ValidityPeriod $validity = null): DiscountRule => DiscountRuleFactory::createOne([
            'workspace' => $workspace,
            'name' => $name,
            'conditions' => $conditions,
            'action' => $action,
            'validity' => $validity,
        ]);
        $rules = [
            $rule('3 stickers pour 10 €', [ConditionSpec::on(3, $sticker)], DiscountAction::fixedPrice(Money::cents(1_000))),
            $rule('2 prints et 1 sticker pour 30 €', [ConditionSpec::on(2, $print), ConditionSpec::on(1, $sticker)], DiscountAction::fixedPrice(Money::cents(3_000))),
            $rule('Prints A3 : −10 %', [ConditionSpec::on(1, $print, 'A3')], DiscountAction::percentOff(1_000)),
            $rule('3 au choix parmi Forêt, Rivière et zines : −2 €', [new ConditionSpec(3, [new TargetSpec($prints[0]), new TargetSpec($prints[1]), new TargetSpec($others[3]->type())])], DiscountAction::amountOff(Money::cents(200))),
            $rule('T-shirt et tote bag : −5 €', [ConditionSpec::on(1, $others[0]), ConditionSpec::on(1, $others[1])], DiscountAction::amountOff(Money::cents(500))),
            $rule(
                'Pin\'s et zine : −10 % (Angoulême)',
                [ConditionSpec::on(1, $others[2]), ConditionSpec::on(1, $others[3])],
                DiscountAction::percentOff(1_000),
                ValidityPeriod::between(new \DateTimeImmutable('-45 days'), new \DateTimeImmutable('-44 days')),
            ),
        ];
        DiscountRuleFactory::createOne(['workspace' => $workspace, 'name' => 'Cadeau', 'action' => DiscountAction::percentOff(10_000), 'manual' => true]);

        $events = [
            EventFactory::new()->withExpenses(['Stand' => 18_000, 'Train' => 9_400, 'Hôtel (2 nuits)' => 16_000, 'Repas' => 4_500])
                ->create(['workspace' => $workspace, 'name' => 'Japan Expo', 'location' => 'Paris Nord Villepinte', 'period' => EventFactory::during('-80 days', '-77 days')]),
            EventFactory::new()->withExpenses(['Stand' => 6_000, 'Essence' => 3_200])
                ->create(['workspace' => $workspace, 'name' => 'Festival de la BD', 'location' => 'Angoulême', 'period' => EventFactory::during('-45 days', '-44 days')]),
            EventFactory::new()->withExpenses(['Emplacement' => 2_500])
                ->create(['workspace' => $workspace, 'name' => 'Marché des créateurs', 'location' => 'Lyon', 'period' => EventFactory::during('-12 days', '-12 days')]),
            EventFactory::new()->withExpenses(['Stand (acompte)' => 5_000])
                ->create(['workspace' => $workspace, 'name' => 'Salon fantastique', 'location' => 'Lille', 'period' => EventFactory::during('+20 days', '+21 days')]),
        ];

        $received = new \DateTimeImmutable('-100 days');
        foreach ([...$stickers, $others[2], $others[3]] as $product) {
            $this->receive($product, null, 150, $received);
        }
        foreach ($prints as $product) {
            foreach ($product->variants() as $variant) {
                $this->receive($product, $variant, 12, $received);
            }
        }
        foreach ($others[0]->variants() as $variant) {
            $this->receive($others[0], $variant, 4, $received);
        }
        foreach ($others[1]->variants() as $variant) {
            $this->receive($others[1], $variant, 15, $received);
        }
        foreach ($this->sleeve->variants() as $variant) {
            $this->receive($this->sleeve, $variant, 300, $received);
        }
        $this->receive($this->envelope, null, 100, $received);
        $this->receive($flyer, null, 500, $received);

        $this->sellDuring($events[0], $catalogue, $rules, 45);
        $this->receive($stickers[0], null, 100, new \DateTimeImmutable('-60 days'), 80);
        $this->sellDuring($events[1], $catalogue, $rules, 25);
        $this->sellDuring($events[2], $catalogue, $rules, 15);

        $pins = $this->stockOf($others[2], null);
        $forestA4 = $this->stockOf($prints[0], 'A4');
        $flyers = $this->stockOf($flyer, null);
        $this->entityManager->persist(StockCheck::take($events[2], new \DateTimeImmutable('-11 days'), [
            new StockCount($flyers, $flyers->onHand() - 35, $flyer->buyingPrice()),
            new StockCount($pins, $pins->onHand() - 2, $others[2]->buyingPrice()),
            new StockCount($forestA4, max(0, $forestA4->onHand()), $prints[0]->buyingPrice()),
        ]));

        $this->purchase($prints, $others[0]);
        $this->designs($sticker, $print, $stickers[0]);
        $this->etsy($stickers, $prints[0]);

        $this->entityManager->flush();
    }

    /**
     * @param list<string> $variants
     */
    private function product(string $reference, string $name, int $selling, int $buying, array $variants = [], ?ProductType $type = null): Product
    {
        $product = ProductFactory::createOne([
            'workspace' => $this->workspace,
            'type' => $type ?? throw new \LogicException('Every product has a type.'),
            'reference' => $reference,
            'name' => $name,
            'sellingPrice' => Money::cents($selling),
            'variants' => $variants,
        ]);
        $product->bought(Money::cents($buying));

        return $product;
    }

    /**
     * @param list<string> $variants
     */
    private function supply(string $reference, string $name, int $buying, array $variants, ProductType $type): Product
    {
        $supply = $this->persisted(Product::supply($this->workspace, $reference, $name, $type, $variants));
        $supply->bought(Money::cents($buying));

        return $supply;
    }

    private function packInto(Order $order, string $sleeve, bool $posted): void
    {
        $supplies = [$this->sleeve->sellable($sleeve)];
        if ($posted) {
            $supplies[] = $this->envelope->sellable(null);
        }
        foreach ($supplies as $supply) {
            $product = $supply->productId?->equals($this->envelope->id()) ? $this->envelope : $this->sleeve;
            $order->useSupply($supply, 1, $this->stockOf($product, $supply->variant)->withdraw(1, $supply->buyingPrice));
        }
    }

    /**
     * @param list<Product> $stickers
     */
    private function etsy(array $stickers, Product $forest): void
    {
        $shop = $this->persisted(SalesChannel::open($this->workspace, 'Boutique Etsy', service: 'etsy'));
        $shop->addCost('Frais de transaction', ChannelCostKind::Fixed, 20);
        $shop->addCost('Commission Etsy', ChannelCostKind::Percent, 650);
        $shop->offerSupplies([$this->sleeve, $this->envelope]);
        foreach ([...$stickers, $forest] as $product) {
            $product->setPriceOn($shop, PriceAdjustment::byCents(100)->applyTo($product->sellingPrice()));
        }
        foreach ([[-40, $stickers[0], 3, 0, 350], [-25, $forest, 1, 150, 490], [-6, $stickers[3], 2, 0, 350]] as [$daysAgo, $product, $quantity, $discount, $shipping]) {
            $variant = $product->hasVariants() ? 'A4' : null;
            $item = $product->sellableOn($shop, $variant);
            $placedAt = new \DateTimeImmutable(\sprintf('%d days 14:00', $daysAgo));
            $receiptId = (string) (3_100_000_000 + abs($daysAgo));
            $order = $this->persisted(Order::imported(
                self::reference('CMD', $placedAt),
                $this->workspace,
                'etsy',
                $receiptId,
                'ETSY-'.$receiptId,
                null,
                $placedAt,
                [(new OrderedItem($item, $quantity))->costing($this->stockOf($product, $variant)->withdraw($quantity, $item->buyingPrice))],
                $item->sellingPrice->multiply($quantity)->subtract(Money::cents($discount)),
                Money::cents($shipping),
                PaymentMethod::Card,
                [],
                'Remise Etsy',
                $shop,
            ));
            $this->packInto($order, $product->hasVariants() ? 'Grande' : 'Petite', true);
            $order->stamp(Money::cents($product->hasVariants() ? 232 : 139));
        }
        $this->entityManager->persist(ServiceConnection::create($this->workspace, 'etsy', ['keystring' => 'mossydemo'], SalesContext::Online, UnknownItems::LinkByHand));
        $this->entityManager->persist(ExternalItem::seen($this->workspace, 'etsy', '1500000042', 'Tote bag brodé mousse forestière — coton bio', 'Noir', new \DateTimeImmutable('-6 days')));
    }

    private function designs(ProductType $sticker, ProductType $print, Product $moss): void
    {
        $square = $this->gabarit('Tirage 15×15', $print, 1_200, [], ['Recadrage carré', 'Fond perdu 3 mm', 'Profil couleur CMJN']);
        $glossy = $this->gabarit('Sticker brillant', $sticker, 400, [], ['Détourage', 'Contour de découpe']);
        $this->gabarit('Sticker mat', $sticker, 450, [], ['Détourage', 'Contour de découpe', 'Contraste renforcé']);

        $undergrowth = $this->persisted(DesignCollection::start($this->workspace, 'Sous-bois', 'Collection d\'automne : champignons, lichens, fougères.'));
        $lichen = $this->persisted(Design::start($this->workspace, 'Lichen', $undergrowth, 'Palette vert-de-gris, texture papier.'));
        $lichenPrint = $lichen->decline($square);
        $lichen->tick($lichenPrint->id(), 'Recadrage carré', true);
        $lichen->decline($glossy);
        $cepe = $this->persisted(Design::start($this->workspace, 'Cèpe', $undergrowth));
        $cepeSticker = $cepe->decline($glossy);
        $cepe->tick($cepeSticker->id(), 'Détourage', true);
        $cepe->tick($cepeSticker->id(), 'Contour de découpe', true);

        $this->persisted(Design::start($this->workspace, 'Héron', null, 'Idée de la brocante de Lyon.'))->workOn(false);
        $this->persisted(Design::fromProduct($this->workspace, $moss, $glossy, $undergrowth, new \DateTimeImmutable('-30 days')));
    }

    /**
     * @param list<string> $variants
     * @param list<string> $adaptations
     */
    private function gabarit(string $name, ProductType $type, int $selling, array $variants, array $adaptations): Gabarit
    {
        return $this->persisted(Gabarit::create($this->workspace, $name, $type, Money::cents($selling), $variants, $adaptations));
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

    /**
     * @param list<Product> $prints
     */
    private function purchase(array $prints, Product $tshirt): void
    {
        $printer = Supplier::create($this->workspace, 'Imprimerie du Lac', 'commandes@imprimerie-du-lac.test', 'Tirages giclée, délai 10 jours.');
        $textile = Supplier::create($this->workspace, 'Atelier Textile', '04 78 00 00 00');
        $this->entityManager->persist($printer);
        $this->entityManager->persist($textile);

        $received = SupplierOrder::place(self::reference('CMF', new \DateTimeImmutable('-20 days')), $printer, new \DateTimeImmutable('-20 days'), [
            new PurchasedItem($prints[0]->sellable('A3'), 10, Money::cents(4_500)),
            new PurchasedItem($prints[1]->sellable('A4'), 20, Money::cents(6_000)),
        ], Money::cents(1_000), Money::cents(1_200));
        [$forest, $river] = $received->lines();
        $receivedAt = new \DateTimeImmutable('-8 days');
        $received->receive([(string) $forest->id() => 12, (string) $river->id() => 19], $receivedAt);
        foreach ([[$prints[0], 'A3', $forest], [$prints[1], 'A4', $river]] as [$product, $variant, $line]) {
            $lot = $this->stockOf($product, $variant)->receive((int) $line->receivedQuantity(), $line->landedCost(), LotOrigin::SupplierOrder, $receivedAt, $received->id());
            $product->bought($lot->unitCost());
        }
        $this->entityManager->persist($received);

        $this->entityManager->persist(SupplierOrder::place(self::reference('CMF', new \DateTimeImmutable('-3 days')), $textile, new \DateTimeImmutable('-3 days'), array_map(
            static fn (string $size): PurchasedItem => new PurchasedItem($tshirt->sellable($size), 6, Money::cents(6 * 1_050)),
            $tshirt->variants(),
        )));
    }

    private function receive(Product $product, ?string $variant, int $quantity, \DateTimeImmutable $at, ?int $unitCost = null): void
    {
        $unitCost ??= $product->buyingPrice()->amount();
        $this->stockOf($product, $variant)->receive($quantity, Money::cents($unitCost * $quantity), LotOrigin::Purchase, $at);
        $product->bought(Money::cents($unitCost));
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

    /**
     * @param list<Product>      $catalogue
     * @param list<DiscountRule> $rules
     */
    private function sellDuring(Event $event, array $catalogue, array $rules, int $orderCount): void
    {
        $days = (int) $event->period()->start()->diff($event->period()->end())->days;

        for ($i = 0; $i < $orderCount; ++$i) {
            $placedAt = $event->period()->start()
                ->modify(\sprintf('+%d days', faker()->numberBetween(0, $days)))
                ->setTime(faker()->numberBetween(10, 18), faker()->numberBetween(0, 59));

            $items = [];
            foreach (faker()->randomElements($catalogue, faker()->numberBetween(1, 3)) as $product) {
                if (!$product instanceof Product) {
                    continue;
                }
                $variant = $product->hasVariants() ? $product->variants()[faker()->numberBetween(0, \count($product->variants()) - 1)] : null;
                $quantity = str_starts_with($product->reference(), 'STI') ? faker()->numberBetween(1, 5) : 1;
                $item = $product->sellable($variant);
                $items[] = (new OrderedItem($item, $quantity))->costing($this->stockOf($product, $variant)->withdraw($quantity, $item->buyingPrice));
            }

            $basket = array_values(array_filter(array_map(static fn (OrderedItem $ordered): ?BasketLine => BasketLine::of($ordered->item, $ordered->quantity), $items)));
            $order = $this->persisted(Order::place(self::reference('CMD', $placedAt), $event, $placedAt, $items, $this->discountCalculator->calculate($basket, $rules, $placedAt), $this->market));
            $printed = array_filter($items, static fn (OrderedItem $ordered): bool => \in_array($ordered->item->variant, ['A5', 'A4', 'A3'], true));
            $this->packInto($order, [] === $printed ? 'Petite' : 'Grande', false);
        }
    }

    private static function reference(string $prefix, \DateTimeImmutable $at): string
    {
        return \sprintf('%s-%s-%s', $prefix, $at->format('Ymd'), substr((string) new Ulid(), -6));
    }
}
