# Events (Événements)

An **event** is a real-world occasion where products are sold: convention, market, fair…

| Field | Meaning |
|---|---|
| `name`, `location` | Required free text (the location is a plain string) |
| `period` | Inclusive range of **whole days** in Europe/Paris (`DateRange`) — a one-day event has start = end |
| `donations` | Money received at the event **without a sale** (donation box, support): optional `label` + strictly positive `amount` |
| `expenses` | Money spent for the event: `label` + strictly positive `amount`, optionally **shared** over a number of events (`sharedOverEvents` ≥ 2) and/or until a day (`sharedUntil`) |

Model: `src/Domain/Event/Event.php`, `Expense.php`, `Donation.php`, `ExpenseSpread.php`, `ExpenseShares.php`, `EventScheduler.php`, `src/Domain/Shared/DateRange.php`.

## Rules

| # | Rule | Where | Tests |
|---|---|---|---|
| E1 | Name and location are required | `Event::describe()` | `EventTest` |
| E2 | End date ≥ start date | `DateRange::fromDates()` | `DateRangeTest` |
| E3 | A moment belongs to an event when its **Paris local date** is within the period (both ends included) | `DateRange::covers()`, `Event::covers()`, `EventRepository::findCovering()` | `DateRangeTest`, `EventUseCasesTest` |
| E4 | **Events of a workspace never overlap** (other workspaces may have events on the same days). Guarantees an order date maps to at most one event (needed to link imported sales to the day's market) | `EventScheduler::ensureFree()` used by `ScheduleEventHandler`/`UpdateEventHandler` | `EventUseCasesTest` |
| E5 | Rescheduling must keep every existing order of the event inside the new period | `UpdateEventHandler` (see [orders](orders.md)) | `OrderUseCasesTest` |
| E6 | Expense label required, amount > 0 — on creation and when revised; expenses can be edited and removed | `Event::addExpense()`, `Event::reviseExpense()`, `Expense::revise()`, `Event::removeExpense()` | `EventTest`, `EventUseCasesTest` |
| E7 | Relative to today (Paris day): an event is **upcoming** before its first day, **ongoing** during its days, **past** from the day after its last day | `Event::timingOn()`, `EventTiming`, `DateRange::isAfter()/isBefore()`; today comes from the Symfony Clock in `ListEventsHandler` | `EventTest`, `ListEventsTest` |
| E8 | A **shared expense** (a table cloth bought once…) is split **equally** between its event and the events starting after it, up to its number of events and/or its end day (whichever is reached first; the event itself always counts). Each event added later re-splits it: alone it counts in full, with a second event each one counts half… Leftover cents go to the earliest events. Every figure using an event's expenses (event list, report, dashboard months and events) uses its shares. The number of events is ≥ 2 and the end day cannot precede the event's first day | `ExpenseSpread`, `ExpenseShares::among()`, `Event::addExpense()` | `EventTest`, `EventApiTest`, `events.spec.js` |

| E9 | A donation has an amount > 0 and an optional label (« Don » when empty); it can be removed, not edited. Donations raise the event's result (report, event list, dashboard: month the event starts, like expenses) but are **not turnover**: no URSSAF, not in the accounting declarations nor exports | `Event::receiveDonation()`, `removeDonation()`, `Donation`, `SalesFigures::of()` | `EventTest`, `EventFiguresTest`, `EventApiTest`, `events.spec.js` |

The profitability of an event is described in [event-report.md](event-report.md).

## Use cases & API

| Use case | Endpoint |
|---|---|
| `ScheduleEvent` | `POST /api/events` `{name, location, startDate, endDate}` (dates `YYYY-MM-DD`) |
| `UpdateEvent` | `PUT /api/events/{id}` (same body) |
| `ListEvents` | `GET /api/events` (most recent first, with expenses total, order count, **turnover and result** computed like the [event report](event-report.md), and `timing`: `upcoming` / `ongoing` / `past`) |
| `GetEvent` | `GET /api/events/{id}` (with `donations` and `donationsTotal`, and its expense shares: its own expenses and those shared from earlier events, each with `amount` = this event's share, `fullAmount`, `sharedBy`, `own`, origin event) |
| `AddExpense` | `POST /api/events/{id}/expenses` `{label, amount, sharedOverEvents?, sharedUntil?}` |
| `ReviseExpense` | `PUT /api/events/{id}/expenses/{expenseId}` `{label, amount, sharedOverEvents?, sharedUntil?}` |
| `RemoveExpense` | `DELETE /api/events/{id}/expenses/{expenseId}` |
| `ReceiveDonation` | `POST /api/events/{id}/donations` `{label?, amount}` |
| `RemoveDonation` | `DELETE /api/events/{id}/donations/{donationId}` |

`ListEvents` also gives each event its number of `days`, `costOfGoods` and `urssaf`; `GetEvent` gives its `timing`.

UI: `/events` — **À venir** (ongoing ones included, « En cours », otherwise « dans N jours »; soonest first) with the **committed expenses** (and the result once it has orders), then **Passés**: bars of each event's result, best first, and a comparison table sortable on every column (days, orders, CA, dépenses, résultat, marge = résultat / CA, résultat par jour); creation modal, `/events/{id}` (details, edit, expenses — « Répartir sur plusieurs événements » in the expense form with « Nombre d'événements » / « Jusqu'au »; shared expenses show their share, the number of events sharing them and the full amount, and those coming from an earlier event link to it and cannot be edited there — report, « Ajouter un don » (amount, optional label; a « Dons » table below the expenses once there is one), « Vérifier les commandes » → order check, see [orders](orders.md) O22, « Faire l'inventaire »).
