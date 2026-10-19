<?php

declare(strict_types=1);

namespace App\Application\Integration\ImportSales;

use App\Application\Integration\AddedConnection;
use App\Application\Integration\ConnectionSession;
use App\Application\Integration\Connectors;
use App\Application\Integration\ExternalSale;
use App\Application\Order\OrderCharges;
use App\Application\Order\OrderPricing;
use App\Application\Reference\ReferenceGenerator;
use App\Application\Sales\OrderChannel;
use App\Application\Stock\StockKeeper;
use App\Application\Transaction;
use App\Application\Translator;
use App\Domain\Event\EventRepository;
use App\Domain\Integration\SalesContext;
use App\Domain\Order\Order;
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
    ) {
    }

    public function __invoke(string $service): ImportReport
    {
        $connector = $this->connectors->get($service);
        $description = $connector->describe();
        $connection = $this->addedConnections->of($connector);

        $sales = [];
        foreach ($connector->sales($this->session->credentials($connection)) as $sale) {
            $sales[$sale->id] ??= $sale;
        }
        uasort($sales, static fn (ExternalSale $a, ExternalSale $b): int => $a->placedAt <=> $b->placedAt);
        $alreadyImported = array_flip($this->orders->importedExternalIds($service, array_map(strval(...), array_keys($sales))));
        $feesUpdated = $this->saleFees->settleImported($service, $sales);

        $catalogue = $this->resolution->catalogue();
        $resolver = $this->resolution->resolver($catalogue, $connection, $description->linePrices);
        $atEvent = SalesContext::AtEvent === $connection->salesContext();
        $rules = $atEvent ? $this->pricing->rules() : [];
        $linked = $this->orderChannel->of($service, null);
        $needsEvent = $atEvent || (null !== $linked && !$linked->acceptsOrderWithoutEvent());
        $discountLabel = $this->translator->trans('import.discount', ['service' => $description->label]);

        $imported = $withoutEvent = $waiting = $empty = 0;
        $datesWithoutEvent = $eventsByDay = $channels = $checks = [];
        foreach ($sales as $id => $sale) {
            if (isset($alreadyImported[(string) $id])) {
                continue;
            }
            if ([] === $sale->lines) {
                ++$empty;
                continue;
            }

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

            $day = BusinessTime::day($sale->placedAt);
            if ($needsEvent && !\array_key_exists($day, $eventsByDay)) {
                $eventsByDay[$day] = $this->events->findCovering($sale->placedAt);
            }
            $event = $needsEvent ? $eventsByDay[$day] : null;
            if ($needsEvent && null === $event) {
                ++$withoutEvent;
                $datesWithoutEvent[$day] = true;
                continue;
            }
            if (!$resolved) {
                ++$waiting;
                continue;
            }

            $eventKey = (string) $event?->id();
            if (!\array_key_exists($eventKey, $channels)) {
                $channels[$eventKey] = $this->orderChannel->of($service, $event);
                $checks[$eventKey] = $this->stock->checksAt($event);
            }

            $items = $this->stock->withdraw($event, $items, $checks[$eventKey]);
            $order = Order::imported(
                $this->references->next(ReferenceKind::Order, ReferenceSubject::at($sale->placedAt)),
                $connection->workspace(),
                $service,
                $sale->id,
                $sale->reference,
                $event,
                $sale->placedAt,
                $items,
                $sale->charged,
                $sale->shipping,
                $sale->paymentMethod,
                $atEvent ? $this->pricing->discounts($items, $sale->placedAt, $rules) : [],
                $discountLabel,
                $channels[$eventKey],
            );
            if (null !== $sale->fee) {
                $order->settleSaleFee($service, $sale->id, $sale->fee);
            }
            $this->charges->charge($order);
            $this->orders->add($order);
            ++$imported;
        }

        $this->transaction->commit();

        $dates = array_keys($datesWithoutEvent);
        sort($dates);

        return new ImportReport(
            $service,
            $description->label,
            $imported,
            \count($alreadyImported),
            $catalogue->createdCount(),
            $catalogue->typesCreatedCount(),
            $withoutEvent,
            array_map(strval(...), $dates),
            $waiting,
            $resolver->itemsToLink(),
            $empty,
            $feesUpdated,
        );
    }
}
