<?php

declare(strict_types=1);

namespace App\Tests\Functional\Application\Discount;

use App\Application\Design\CreateCollection\CreateCollectionHandler;
use App\Application\Design\CreateGabarit\CreateGabarit;
use App\Application\Design\CreateGabarit\CreateGabaritHandler;
use App\Application\Design\DeleteCollection\DeleteCollectionHandler;
use App\Application\Design\DesignProduct\DesignProductHandler;
use App\Application\Discount\CreateDiscountRule\CreateDiscountRuleHandler;
use App\Application\Discount\DeleteDiscountRule\DeleteDiscountRuleHandler;
use App\Application\Discount\DiscountRuleDefinition;
use App\Application\Discount\ListDiscountRules\ListDiscountRulesHandler;
use App\Application\Discount\UpdateDiscountRule\UpdateDiscountRuleHandler;
use App\Application\Event\ScheduleEvent\ScheduleEvent;
use App\Application\Event\ScheduleEvent\ScheduleEventHandler;
use App\Application\Order\GetOrder\GetOrderHandler;
use App\Application\Order\PlaceOrder\PlaceOrder;
use App\Application\Order\PlaceOrder\PlaceOrderHandler;
use App\Application\Order\PreviewOrder\PreviewOrderHandler;
use App\Application\Order\RequestedLine;
use App\Application\Product\CreateProduct\CreateProductHandler;
use App\Application\Product\CreateProductType\CreateProductTypeHandler;
use App\Domain\Discount\Exception\OnlyEligibleCollection;
use App\Domain\Product\Exception\UnknownTypeVariant;
use App\Domain\Shared\Exception\NotFound;
use App\Tests\Support\ActsAsUser;
use App\Tests\Support\CreatesProducts;
use App\Tests\Support\DiscountRules;
use App\Tests\Support\DomainExceptions;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class DiscountRuleUseCasesTest extends KernelTestCase
{
    use ActsAsUser;
    use CreatesProducts;

    protected function setUp(): void
    {
        self::actAsMemberOf();
    }

    public function testDiscountRuleLifecycle(): void
    {
        $create = self::getContainer()->get(CreateProductHandler::class);
        $sticker = (string) self::createProduct('Sticker', 400);
        $bigSticker = (string) self::createProduct('Sticker XL', 600);

        $id = (string) self::getContainer()->get(CreateDiscountRuleHandler::class)(new DiscountRuleDefinition(
            '3 stickers pour 10 €',
            [DiscountRules::product($sticker, 3)],
            'fixedPrice',
            1_000,
        ));
        self::getContainer()->get(UpdateDiscountRuleHandler::class)($id, new DiscountRuleDefinition(
            'Sticker et sticker XL : −10 %',
            [DiscountRules::product($sticker, 1), DiscountRules::product($bigSticker, 1)],
            'percentOff',
            1_000,
            '2026-07-01',
            '2026-07-31',
        ));
        self::getContainer()->get('doctrine')->getManager()->clear();

        $rule = self::getContainer()->get(ListDiscountRulesHandler::class)()[0];
        self::assertSame('Sticker et sticker XL : −10 %', $rule->name);
        self::assertSame(['kind' => 'percentOff', 'value' => 1_000], $rule->action);
        self::assertSame(['2026-07-01', '2026-07-31'], [$rule->startsOn, $rule->endsOn]);
        self::assertSame('expired', $rule->status);
        self::assertSame([['Sticker', 1], ['Sticker XL', 1]], array_map(static fn (array $c): array => [$c['targets'][0]['name'], $c['quantity']], $rule->conditions));

        self::getContainer()->get(DeleteDiscountRuleHandler::class)($id);
        self::assertSame([], self::getContainer()->get(ListDiscountRulesHandler::class)());
    }

    public function testTwoPrintsAndOneStickerForFifteenEurosDuringItsPeriod(): void
    {
        $print = (string) self::getContainer()->get(CreateProductTypeHandler::class)('Print')->id();
        $sticker = (string) self::getContainer()->get(CreateProductTypeHandler::class)('Sticker')->id();
        $create = self::getContainer()->get(CreateProductHandler::class);
        $foret = (string) self::createProduct('Forêt', 1_500, typeId: $print);
        $mousse = (string) self::createProduct('Mousse', 400, typeId: $sticker);
        self::getContainer()->get(CreateDiscountRuleHandler::class)(new DiscountRuleDefinition(
            '2 prints et 1 sticker pour 15 €',
            [DiscountRules::type($print, 2), DiscountRules::type($sticker, 1)],
            'fixedPrice',
            1_500,
            '2026-07-09',
            '2026-07-09',
        ));
        self::getContainer()->get(ScheduleEventHandler::class)(new ScheduleEvent('Salon', 'Lyon', new \DateTimeImmutable('2026-07-09'), new \DateTimeImmutable('2026-07-10')));
        $lines = [new RequestedLine($foret, null, 2), new RequestedLine($mousse, null, 1)];

        $during = self::getContainer()->get(PreviewOrderHandler::class)(new \DateTimeImmutable('2026-07-09 12:00'), $lines);
        $after = self::getContainer()->get(PreviewOrderHandler::class)(new \DateTimeImmutable('2026-07-10 12:00'), $lines);

        self::assertSame(1_500, $during->total);
        self::assertSame('2 prints et 1 sticker pour 15 €', $during->discounts[0]['label']);
        self::assertNotNull($during->discounts[0]['ruleId']);
        self::assertSame(3_400, $after->total);
    }

    public function testUnknownProductIsRejected(): void
    {
        $this->expectException(NotFound::class);

        self::getContainer()->get(CreateDiscountRuleHandler::class)(new DiscountRuleDefinition(
            'Lot',
            [DiscountRules::product('01K00000000000000000000000', 3)],
            'fixedPrice',
            1_000,
        ));
    }

    public function testADiscountOnA3PrintsOnlyAppliesToA3Lines(): void
    {
        $print = (string) self::getContainer()->get(CreateProductTypeHandler::class)('Print', variants: ['A4', 'A3'])->id();
        $foret = self::createProduct('Forêt', 1_500, variants: ['A4', 'A3'], typeId: $print);
        $lac = self::createProduct('Lac', 1_500, variants: ['A3'], typeId: $print);
        $rule = (string) self::getContainer()->get(CreateDiscountRuleHandler::class)(new DiscountRuleDefinition('−3 € sur les prints A3', [DiscountRules::type($print, 1, 'a3')], 'amountOff', 300));
        self::getContainer()->get(ScheduleEventHandler::class)(new ScheduleEvent('Salon', 'Lyon', new \DateTimeImmutable('2030-03-14'), new \DateTimeImmutable('2030-03-15')));

        $order = (string) self::getContainer()->get(PlaceOrderHandler::class)(new PlaceOrder(
            new \DateTimeImmutable('2030-03-14 12:00'),
            [new RequestedLine($foret, 'A3', 1), new RequestedLine($foret, 'A4', 2), new RequestedLine($lac, 'A3', 1)],
        ))->id();

        $view = self::getContainer()->get(GetOrderHandler::class)($order);
        self::assertSame([['label' => '−3 € sur les prints A3 ×2', 'amount' => 600, 'ruleId' => $rule]], $view->discounts);
        self::assertSame(6_000 - 600, $view->total);
        self::assertSame(['Print · A3'], DiscountRules::names(self::getContainer()->get(ListDiscountRulesHandler::class)()[0]));
    }

    public function testThreeProductsChosenAmongSeveralProductsAndTypesAreDiscounted(): void
    {
        $prints = (string) self::getContainer()->get(CreateProductTypeHandler::class)('Print', variants: ['8x8', '15x15'])->id();
        $zines = (string) self::getContainer()->get(CreateProductTypeHandler::class)('Zine')->id();
        $mossy = self::createProduct('Mossy', 600, variants: ['15x15'], typeId: $prints);
        $eevee = self::createProduct('Évoli', 400, variants: ['8x8'], typeId: $prints);
        $zine = self::createProduct('Sous-bois', 1_000, typeId: $zines);
        $rule = (string) self::getContainer()->get(CreateDiscountRuleHandler::class)(new DiscountRuleDefinition(
            '3 prints Mossy ou Évoli : −2 €',
            [DiscountRules::anyOf(3, DiscountRules::productTarget($mossy), DiscountRules::productTarget($eevee, '8x8'))],
            'amountOff',
            200,
        ));
        self::getContainer()->get(ScheduleEventHandler::class)(new ScheduleEvent('Salon', 'Lyon', new \DateTimeImmutable('2030-03-14'), new \DateTimeImmutable('2030-03-15')));

        $order = (string) self::getContainer()->get(PlaceOrderHandler::class)(new PlaceOrder(
            new \DateTimeImmutable('2030-03-14 12:00'),
            [new RequestedLine($mossy, '15x15', 1), new RequestedLine($eevee, '8x8', 2), new RequestedLine($zine, null, 1)],
        ))->id();

        self::assertSame([['label' => '3 prints Mossy ou Évoli : −2 €', 'amount' => 200, 'ruleId' => $rule]], self::getContainer()->get(GetOrderHandler::class)($order)->discounts);
        self::assertSame(['Print Mossy / Print Évoli · 8x8'], DiscountRules::names(self::getContainer()->get(ListDiscountRulesHandler::class)()[0]));
    }

    public function testAConditionVariantMustBeOneOfTheType(): void
    {
        $print = (string) self::getContainer()->get(CreateProductTypeHandler::class)('Print', variants: ['A4'])->id();

        DomainExceptions::assertThrown(
            new UnknownTypeVariant('Print', 'A3'),
            static fn () => self::getContainer()->get(CreateDiscountRuleHandler::class)(new DiscountRuleDefinition('A3', [DiscountRules::type($print, 1, 'A3')], 'amountOff', 300)),
        );
    }

    public function testADiscountOnATypeOfOneCollectionOnlyAppliesToItsProductsOfThatCollection(): void
    {
        $stickers = (string) self::getContainer()->get(CreateProductTypeHandler::class)('Sticker')->id();
        $gabarit = (string) self::getContainer()->get(CreateGabaritHandler::class)(new CreateGabarit('Sticker 5 cm', $stickers, 400, [], []))->id();
        $eeveeCollection = (string) self::getContainer()->get(CreateCollectionHandler::class)('Eevee', null);
        $eevee = self::createProduct('Évoli', 400, typeId: $stickers);
        $sylveon = self::createProduct('Nymphali', 400, typeId: $stickers);
        $mossy = self::createProduct('Mossy', 500, typeId: $stickers);
        self::getContainer()->get(DesignProductHandler::class)($eevee, $gabarit, $eeveeCollection);
        self::getContainer()->get(DesignProductHandler::class)($sylveon, $gabarit, $eeveeCollection);
        $rule = (string) self::getContainer()->get(CreateDiscountRuleHandler::class)(new DiscountRuleDefinition(
            '1 sticker Eevee : −1 €',
            [DiscountRules::anyOf(1, DiscountRules::typeTarget($stickers, collectionId: $eeveeCollection))],
            'amountOff',
            100,
        ));

        $preview = self::getContainer()->get(PreviewOrderHandler::class)(new \DateTimeImmutable('2030-03-14 12:00'), [new RequestedLine($eevee, null, 1), new RequestedLine($sylveon, null, 1), new RequestedLine($mossy, null, 2)]);

        self::assertSame([['label' => '1 sticker Eevee : −1 € ×2', 'amount' => 200, 'ruleId' => $rule]], $preview->discounts);
        self::assertSame(['Sticker · Eevee'], DiscountRules::names(self::getContainer()->get(ListDiscountRulesHandler::class)()[0]));
        DomainExceptions::assertThrown(
            new OnlyEligibleCollection('1 sticker Eevee : −1 €', 'Eevee'),
            static fn () => self::getContainer()->get(DeleteCollectionHandler::class)($eeveeCollection),
        );
    }
}
