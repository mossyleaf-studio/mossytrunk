# Planning (Planning de sortie)

A collection or a design gets a **release** (« sortie »): a day or an **event**, plus ordered steps (« finaliser les
fichiers », « commander l'impression »…) that each last a number of days. The steps are dated backward from the release.
See [designs.md](designs.md) for collections and designs.

| Concept | Fields |
|---|---|
| `ReleaseTemplate` | One per workspace: the default ordered steps (label, days), set in Paramètres |
| `Release` | Owner (a collection **or** a design, one release at most each), release moment (a day **or** an event), steps (label, days ≥ 1, done) |
| `ScheduledStep` | A step with its start and end days (Paris), its state: `done`, `late`, `ongoing`, `upcoming` |
| `Restock` | « Réassort »: a supplier, an optional note (« stickers holo »), a target (a day **or** an event), a lead time in days (≥ 1), the day it was ordered and the supplier order it was placed through (both optional) |

Model: `src/Domain/Planning/`.

## Rules

| # | Rule | Where | Tests |
|---|---|---|---|
| R1 | A release belongs to exactly one owner, a collection or a design, and an owner has at most one release; deleting the owner deletes its release | `Release::ofCollection()`, `ofDesign()`, `PlanCollectionReleaseHandler`, `PlanDesignReleaseHandler`, `ReleaseAlreadyPlanned` | `ReleaseTest`, `PlanningUseCasesTest` |
| R2 | A release has exactly one moment: a day or an event; the release day of an event is its first day, so rescheduling the event moves the whole plan | `Release::schedule()`, `releaseDay()`, `TargetDay`, `DayOrEventMissing`, `DayAndEvent` | `ReleaseTest`, `PlanningUseCasesTest`, `PlanningApiTest` |
| R3 | Steps are chained backward: the last one ends the day before the release, each earlier one ends the day before the next starts; days are Paris days (daylight saving changes do not shift them) | `ReleaseSchedule::of()` | `ReleaseScheduleTest` |
| R4 | Step labels are trimmed, non-empty and unique; a step lasts at least 1 day; steps keep their order | `StepList::clean()`, `EmptyStepLabel`, `DuplicateStepLabel`, `NonPositiveStepDuration` | `ReleaseTest`, `ReleaseTemplateTest`, `PlanningApiTest` |
| R5 | A design without its own release inherits its collection's (`inherited: true`), shown read-only on the design (its steps are ticked on the collection, the shelf or the planning); its own release wins | `GetDesignReleaseHandler`, `DesignDetailPage` | `PlanningUseCasesTest`, `PlanningApiTest`, `planning.spec.js` |
| R6 | The workspace template only pre-fills the steps of a new release; each release then has its own steps (added, removed, resized, reordered, ticked) and later template changes do not alter it | `DefineReleaseTemplateHandler`, `Release::replan()`, `tick()` | `ReleaseTemplateTest`, `PlanningUseCasesTest` |
| R7 | A step is `done` once ticked, else `late` when it ended before today, `ongoing` between its start and end days, `upcoming` otherwise | `ScheduledStep::stateOn()`, `StepState` | `ReleaseScheduleTest` |
| R8 | The planning groups the steps left to do: `late`, « cette semaine » (ongoing, or starting within the next 6 days) and `later`; done steps and steps of releases already past (release day before today) are left out of the groups, though those releases are still listed | `GetPlanningHandler`, `Release::isPastOn()` | `PlanningUseCasesTest` |

| R9 | A restock targets a day or an event like a release (R2), not before today (an event's first day, so a started event offers none); it must be ordered by its **order-by day** = target day − lead time (Paris days); the lead time is pre-filled from the supplier's « Délai de commande » (see [supplier-orders.md](supplier-orders.md) F11) and then belongs to the restock | `Restock::plan()`, `revise()`, `orderBy()`, `LeadTime`, `NonPositiveLeadTime`, `PastRestockTarget` | `RestockTest`, `RestockUseCasesTest` |
| R10 | A restock is ordered either by ticking it (ordered today; unticking clears the day and the linked order) or through a supplier order of **its** supplier (ordered on that order's day, linked to it); an order of another supplier is refused, and a linked restock cannot move to another supplier. Ticking a linked restock drops the link. The restock follows its order: redating the order moves its ordered day, moving the order to another supplier drops the link (still ordered, on the order's day), merging it into another order links the restock to the order that absorbs it, deleting it keeps the restock ordered without the link | `Restock::markOrdered()`, `markNotOrdered()`, `orderedThrough()`, `followOrder()`, `OrderedRestocks`, `TickRestockHandler`, `OrderRestockHandler`, `RestockFromAnotherSupplier` | `RestockTest`, `RestockUseCasesTest` |
| R11 | A restock is `done` once ordered, else `late` when its order-by day is before today, `upcoming` otherwise; pending restocks whose target day is not past join the planning groups next to the steps (`kind: restock`, dated by their order-by day): `late`, « cette semaine » (order-by day within the next 6 days), `later`. « Réassorts à venir » lists every restock whose target day is not past, ordered or not, by order-by day | `Restock::stateOn()`, `isPastOn()`, `GetPlanningHandler` | `RestockTest`, `RestockUseCasesTest` |

Releases, restocks and the template are scoped to the workspace (see [accounts.md](accounts.md)).

## Use cases & API

| Use case | Endpoint |
|---|---|
| `GetReleaseTemplate` / `DefineReleaseTemplate` | `GET` / `PUT /api/release-template` `{steps}` |
| `GetPlanning` | `GET /api/planning` → `{late, thisWeek, later, releases, restocks}` (group items carry `kind`: `step` or `restock`) |
| `GetDesignRelease` | `GET /api/designs/{id}/release` → `{release, inherited}` |
| `PlanCollectionRelease` / `PlanDesignRelease` | `POST /api/design-collections/{id}/release` / `POST /api/designs/{id}/release` `{day` or `eventId, steps}` → `{id}` |
| `ReplanRelease` / `CancelRelease` | `PUT` / `DELETE /api/releases/{id}` |
| `TickReleaseStep` | `PUT /api/releases/{id}/steps` `{step, done}` |
| `PlanRestock` | `POST /api/restocks` `{supplierId, note?, day` or `eventId, leadDays}` → `{id}` |
| `ReviseRestock` / `CancelRestock` | `PUT` (same body) / `DELETE /api/restocks/{id}` |
| `TickRestock` | `PUT /api/restocks/{id}/ordered` `{ordered}` |
| `OrderRestock` | `PUT /api/restocks/{id}/supplier-order` `{supplierOrderId}` |

## UI

- **Paramètres › « Étapes de sortie »**: edit the template (label, days, move up/down, remove, add), one save button.
- **Collection shelf** (`/designs`): « Planifier la sortie » when the collection has no release; otherwise its timeline (release day and event, each step with dates, a state badge and a checkbox) with « Modifier » and « Annuler la sortie ». The release form picks a date or an event and starts from the template (or the current steps).
- **Design** (`/designs/{id}`): its own release shown like on the shelf; when inherited, « Sortie de la collection X » (linking to the collection), its timeline read-only, with « Planifier ce design à part ».
- **Planning** (`/planning`, workshop menu after Créations): groups « En retard », « Cette semaine », « Plus tard » (step, owner link, dates, checkbox), then « Sorties à venir » with one timeline per release.
- **Dashboard**: a « Planning » card listing the late and this week's steps with a link to `/planning`, hidden when both are empty.
- **Planning header**: « Préparer un réassort » opens a form (supplier, note, a date or an event, « Délai de commande » in days pre-filled from the supplier); the **event page** of an upcoming event has the same button with the event pre-selected. The form loads the suppliers and events when it opens (not preloaded with the page).
- **Planning items**: a pending restock sits in the groups next to the steps (supplier, note, target, order-by day) with a « Commandé » checkbox and « Passer la commande », which opens the supplier order drawer with that supplier (the restock is read once from the URL, `takeQuery`, and not kept in the list memory); saving the order links it to the restock (R10) in a second request: if the link fails (the supplier was changed in the drawer), the order stays placed and an error toast says why, and the restock can still be ticked by hand. « Réassorts à venir » lists the restocks whose target day is not past, with the order state and the linked order; each can be edited or deleted.
- **Dashboard**: the « Planning » card also lists the late and this week's pending restocks.
