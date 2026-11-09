<?php

declare(strict_types=1);

namespace App\Application\Integration\ImportSales;

use App\Application\Integration\AddedConnection;
use App\Application\Integration\ConnectionSession;
use App\Application\Integration\Connectors;
use App\Application\Integration\ExternalSale;
use App\Application\Integration\SalesConnector;
use App\Application\Order\OrderCharges;
use App\Application\Order\OrderPricing;
use App\Application\Reference\ReferenceGenerator;
use App\Application\Sales\OrderChannel;
use App\Application\Stock\StockKeeper;
use App\Application\Transaction;
use App\Application\Translator;
use App\Domain\Event\Event;
use App\Domain\Event\EventRepository;
use App\Domain\Integration\SalesContext;
use App\Domain\Integration\ServiceConnection;
use App\Domain\Order\Order;
use App\Domain\Order\OrderedItem;
use App\Domain\Order\OrderRepository;
use App\Domain\Reference\ReferenceKind;
use App\Domain\Reference\ReferenceSubject;
use App\Domain\Shared\BusinessTime;

final readonly class ImportSalesHandler
{
    public function __construct(
        private OrderCharges $charges,
        private Connectors $connectors,
        private AddedConnection $addedConnections,
        private ConnectionSession $session,
        private ExternalItemResolution $resolution,
        private EventRepository $events,
        private OrderRepository $orders,
        private OrderPricing $pricing,
        private StockKeeper $stock,
        private ReferenceGenerator $references,
        private Transaction $transaction,
        private Translator $translator,
        private OrderChannel $orderChannel,
        private SaleFees $saleFees,
        private SaleCancellations $cancellations,
    ) {
    }

    public function __invoke(string $service): ImportReport
    {
        $connector = $this->connectors->get($service);
        $description = $connector->describe();
        $connection = $this->addedConnections->of($connector);

        $sales = $this->salesOf($connector, $connection);
        $alreadyImported = array_flip($this->orders->importedExternalIds($service, array_map(strval(...), array_keys($sales))));
        $feesUpdated = $this->saleFees->settleImported($service, $sales);
        $cancelled = $this->cancellations->cancelImported($service, $sales);

        $catalogue = $this->resolution->catalogue();
        $import = $this->start($service, $connection, $this->resolution->resolver($catalogue, $connection, $description->linePrices), $description->label);
        $outcomes = [];
        foreach (array_diff_key($sales, $alreadyImported) as $id => $sale) {
            $outcomes[$id] = $this->importSale($sale, $import);
        }

        $this->transaction->commit();

        return new ImportReport(
            $service,
            $description->label,
            self::countOf(SaleOutcome::Imported, $outcomes),
            \count($alreadyImported),
            $catalogue->createdCount(),
            $catalogue->typesCreatedCount(),
            self::countOf(SaleOutcome::WithoutEvent, $outcomes),
            self::datesWithoutEvent($sales, $outcomes),
            self::countOf(SaleOutcome::WaitingForItems, $outcomes),
            $import->resolver->itemsToLink(),
            self::countOf(SaleOutcome::WithoutItems, $outcomes),
            $feesUpdated,
            $cancelled,
        );
    }

    /**
     * @return array<string, ExternalSale>
     */
    private function salesOf(SalesConnector $connector, ServiceConnection $connection): array
    {
        $sales = [];
        foreach ($connector->sales($this->session->credentials($connection)) as $sale) {
            $sales[$sale->id] ??= $sale;
        }
        uasort($sales, static fn (ExternalSale $a, ExternalSale $b): int => $a->placedAt <=> $b->placedAt);

        return $sales;
    }

    private function start(string $service, ServiceConnection $connection, ExternalItemResolver $resolver, string $label): SalesImport
    {
        $atEvent = SalesContext::AtEvent === $connection->salesContext();
        $rules = $atEvent ? $this->pricing->rules() : null;
        $linked = $this->orderChannel->of($service, null);
        $needsEvent = $atEvent || (null !== $linked && !$linked->acceptsOrderWithoutEvent());

        return new SalesImport($service, $connection, $resolver, $needsEvent, $rules, $this->translator->trans('import.discount', ['service' => $label]), $this->events, $this->orderChannel, $this->stock);
    }

    private function importSale(ExternalSale $sale, SalesImport $import): SaleOutcome
    {
        if ([] === $sale->lines) {
            return SaleOutcome::WithoutItems;
        }
        $items = self::itemsOf($sale, $import->resolver);
        $event = $import->needsEvent ? $import->eventCovering($sale->placedAt) : null;
        if ($import->needsEvent && null === $event) {
            return SaleOutcome::WithoutEvent;
        }
        if (null === $items) {
            return SaleOutcome::WaitingForItems;
        }
        $this->place($sale, $event, $items, $import);

        return SaleOutcome::Imported;
    }

    /**
     * @return list<OrderedItem>|null
     */
    private static function itemsOf(ExternalSale $sale, ExternalItemResolver $resolver): ?array
    {
        $items = [];
        $resolved = true;
        foreach ($sale->lines as $line) {
            $ordered = $resolver->resolve($line, $sale->placedAt);
            if (null === $ordered) {
                $resolved = false;
                continue;
            }
            array_push($items, ...$ordered);
        }

        return $resolved ? $items : null;
    }

    /**
     * @param list<OrderedItem> $items
     */
    private function place(ExternalSale $sale, ?Event $event, array $items, SalesImport $import): void
    {
        $items = $this->stock->withdraw($event, $items, $import->checksAt($event));
        $order = Order::imported(
            $this->references->next(ReferenceKind::Order, ReferenceSubject::at($sale->placedAt)),
            $import->connection->workspace(),
            $import->service,
            $sale->id,
            $sale->reference,
            $event,
            $sale->placedAt,
            $items,
            $sale->charged,
            $sale->shipping,
            $sale->paymentMethod,
            null === $import->rules ? [] : $this->pricing->discounts($items, $sale->placedAt, $import->rules),
            $import->discountLabel,
            $import->channelAt($event),
        );
        if (null !== $sale->fee) {
            $order->settleSaleFee($import->service, $sale->id, $sale->fee);
        }
        $this->charges->charge($order);
        $this->orders->add($order);
        if (null !== $sale->cancelledAt) {
            $this->cancellations->cancel($order, $sale->cancelledAt);
        }
    }

    /**
     * @param array<array-key, SaleOutcome> $outcomes
     */
    private static function countOf(SaleOutcome $outcome, array $outcomes): int
    {
        return \count(array_filter($outcomes, static fn (SaleOutcome $each): bool => $each === $outcome));
    }

    /**
     * @param array<array-key, ExternalSale> $sales
     * @param array<array-key, SaleOutcome>  $outcomes
     *
     * @return list<string>
     */
    private static function datesWithoutEvent(array $sales, array $outcomes): array
    {
        $dates = [];
        foreach (array_keys($outcomes, SaleOutcome::WithoutEvent, true) as $id) {
            $dates[BusinessTime::day($sales[$id]->placedAt)] = true;
        }
        $dates = array_map(strval(...), array_keys($dates));
        sort($dates);

        return $dates;
    }
}
