# Planning (Planning de sortie)

A collection or a design gets a **release** (« sortie »): a day or an **event**, plus ordered steps (« finaliser les
fichiers », « commander l'impression »…) that each last a number of days. The steps are dated backward from the release.
Everything prepared for an **event** (releases, restocks) must be ready on its **ready-by day** (« jour de préparation »):
the event's first day minus a margin of days. See [designs.md](designs.md) for collections and designs and
[events.md](events.md) for the event side.

| Concept | Fields |
|---|---|
| `PlanningSettings` | One per workspace: the default ordered steps (label, days) and the default **ready-by margin** « Prêt … jours avant l'événement » (integer ≥ 0, default 3), set in Paramètres |
| `Release` | Owner (a collection **or** a design, one release at most each), release moment (a day **or** an event), steps (label, days ≥ 1, done) |
| `ScheduledStep` | A step with its start and end days (Paris), its state: `done`, `late`, `ongoing`, `upcoming` |
| `Restock` | « Réassort »: a supplier, an optional note (« stickers holo »), an **event** (restocks created before events existed keep a plain day, « legacy »), a lead time in days (≥ 1), the day it was ordered and the supplier order it was placed through (both optional) |

Model: `src/Domain/Planning/`; the margin override lives on `src/Domain/Event/Event.php` (`ReadyMargin`).

**Ready-by day** = the event's first day minus its margin (Paris days): the event's own override (`Event::planReadiness()`, empty = workspace default) or the workspace default of `PlanningSettings`. Computed by `Event::readyBy()` and, with today and the default margin, by `Application\Planning\PlanningCalendar` (`PlanningDay`).

## Rules

| # | Rule | Where | Tests |
|---|---|---|---|
| R1 | A release belongs to exactly one owner, a collection or a design, and an owner has at most one release; deleting the owner deletes its release | `Release::ofCollection()`, `ofDesign()`, `PlanCollectionReleaseHandler`, `PlanDesignReleaseHandler`, `ReleaseAlreadyPlanned` | `ReleaseTest`, `PlanningUseCasesTest` |
| R2 | A release has exactly one moment: a day or an event; the release day of an event is its first day (`releaseDay()`), so rescheduling the event moves the whole plan | `Release::schedule()`, `releaseDay()`, `TargetDay`, `DayOrEventMissing`, `DayAndEvent` | `ReleaseTest`, `PlanningUseCasesTest`, `PlanningApiTest` |
| R3 | Steps are chained backward: on a **plain day** the last one ends the day before it; on an **event** it ends **on the event's ready-by day** (R12); each earlier one ends the day before the next starts; days are Paris days (daylight saving changes do not shift them) | `ReleaseSchedule::endingOn()`, `Release::scheduledSteps()` | `ReleaseScheduleTest`, `ReleaseTest`, `PlanningUseCasesTest` |
| R4 | Step labels are trimmed, non-empty and unique; a step lasts at least 1 day; steps keep their order | `StepList::clean()`, `EmptyStepLabel`, `DuplicateStepLabel`, `NonPositiveStepDuration` | `ReleaseTest`, `PlanningSettingsTest`, `PlanningApiTest` |
| R5 | A design without its own release inherits its collection's (`inherited: true`), shown read-only on the design (its steps are ticked on the collection, the shelf or the planning); its own release wins | `GetDesignReleaseHandler`, `DesignDetailPage` | `PlanningUseCasesTest`, `PlanningApiTest`, `planning.spec.js` |
| R6 | The workspace settings' steps only pre-fill the steps of a new release; each release then has its own steps (added, removed, resized, reordered, ticked) and later settings changes do not alter it | `DefinePlanningSettingsHandler`, `Release::replan()`, `tick()` | `PlanningSettingsTest`, `PlanningUseCasesTest` |
| R7 | A step is `done` once ticked, else `late` when it ended before today, `ongoing` between its start and end days, `upcoming` otherwise | `ScheduledStep::stateOn()`, `StepState` | `ReleaseScheduleTest` |
| R8 | The planning groups the steps left to do: `late`, « cette semaine » (ongoing, or starting within the next 6 days) and `later`; done steps and steps of releases already past (release day before today) are left out of the groups, though those releases are still listed | `GetPlanningHandler`, `Release::isPastOn()` | `PlanningUseCasesTest` |

| R9 | A restock is **prepared for an event** (required on plan and on revise, `RestockWithoutEvent`; not a started or past event, `PastRestockTarget`); it must be ordered by its **order-by day** = the event's ready-by day − lead time (Paris days); the lead time is pre-filled from the supplier's « Délai de commande » (see [supplier-orders.md](supplier-orders.md) F11) and then belongs to the restock. **Legacy** restocks created on a plain day keep working as before (ready-by = target = that day, margin-independent) and still show in the planning; revising one asks for an event and drops the day | `Restock::plan()`, `revise()`, `readyBy()`, `orderBy()`, `targetDay()`, `LeadTime`, `NonPositiveLeadTime`, `RestockWithoutEvent`, `PastRestockTarget` | `RestockTest`, `RestockUseCasesTest`, `RestockApiTest` |
| R10 | A restock is ordered either by ticking it (ordered today; unticking clears the day and the linked order) or through a supplier order of **its** supplier (ordered on that order's day, linked to it); an order of another supplier is refused, and a linked restock cannot move to another supplier. Ticking a linked restock drops the link. The restock follows its order: redating the order moves its ordered day, moving the order to another supplier drops the link (still ordered, on the order's day), merging it into another order links the restock to the order that absorbs it, deleting it keeps the restock ordered without the link | `Restock::markOrdered()`, `markNotOrdered()`, `orderedThrough()`, `followOrder()`, `OrderedRestocks`, `TickRestockHandler`, `OrderRestockHandler`, `RestockFromAnotherSupplier` | `RestockTest`, `RestockUseCasesTest` |
| R11 | A restock is `done` once ordered, else `late` when its order-by day is before today, `upcoming` otherwise; restocks whose target day (event first day, or the legacy day) is not past join the planning groups next to the steps (`kind: restock`, dated by their order-by day): `late`, « cette semaine » (order-by day within the next 6 days), `later`. An ordered restock stays there, ticked, in « cette semaine » or `later` until its order-by day passes (so a tick ahead of time can be undone from the planning, a legacy restock included), then leaves the groups like a done step; one ordered after its order-by day leaves them at once; the summary counter, the next deadline and the dashboard count only what is left to do. The event's restocks are listed on its page (R15) | `Restock::stateOn()`, `isPastOn()`, `GetPlanningHandler` | `RestockTest`, `RestockUseCasesTest` |

| R12 | The **ready-by margin** is a workspace default (`PlanningSettings`, integer ≥ 0, default 3) overridable per event (optional, empty = default; negative refused, `NegativeReadyMargin`; in Paramètres the default cannot be left empty); the ready-by day is the event's first day minus that margin, so rescheduling the event moves it | `ReadyMargin`, `PlanningSettings::define()`, `Event::planReadiness()`, `readyBy()` | `EventReadinessTest`, `PlanningSettingsTest`, `EventUseCasesTest`, `EventApiTest` |
| R13 | `GetPlanning` also gives `today` (the server's Paris day, the only clock the UI uses for relative labels) and `events`: every event **not over yet** (ongoing or upcoming, soonest first) with its dates, `readyBy` and effective `readyDaysBefore`, so the timeline draws them even with nothing planned. Releases and restocks keep their event id | `GetPlanningHandler`, `PlanningView`, `EventRepository::notOverOn()` | `PlanningUseCasesTest`, `PlanningApiTest` |
| R14 | `GetEventPreparation` gathers an event's ready-by day, effective margin, releases (by creation) and restocks (by order-by day, ordered ones included); another workspace's event is not found | `GetEventPreparationHandler`, `ReleaseRepository::ofEvent()`, `RestockRepository::ofEvent()` | `EventPreparationTest`, `PlanningApiTest` |
| R15 | The event page's « Préparation » section shows the ready-by day (« Prêt le 10 déc. · 3 jours avant »), the releases targeting the event, its restocks and « Préparer un réassort » with the event preset (see UI) | `EventDetailPage` | `PlanningApiTest`, `planning.spec.js` |

Releases, restocks and the settings are scoped to the workspace (see [accounts.md](accounts.md)).

## Use cases & API

| Use case | Endpoint |
|---|---|
| `GetPlanningSettings` / `DefinePlanningSettings` | `GET` / `PUT /api/planning-settings` `{steps, readyDaysBefore}` (replaces `/api/release-template`; a negative margin is a 422) |
| `GetEventPreparation` | `GET /api/events/{id}/preparation` → `{event, readyBy, readyDaysBefore, releases, restocks}` (preloaded by the event page) |
| `GetPlanning` | `GET /api/planning` → `{today, events, late, thisWeek, later, releases, restocks}` (group items carry `kind`: `step` or `restock`) |
| `GetDesignRelease` | `GET /api/designs/{id}/release` → `{release, inherited}` |
| `PlanCollectionRelease` / `PlanDesignRelease` | `POST /api/design-collections/{id}/release` / `POST /api/designs/{id}/release` `{day` or `eventId, steps}` → `{id}` |
| `ReplanRelease` / `CancelRelease` | `PUT` / `DELETE /api/releases/{id}` |
| `TickReleaseStep` | `PUT /api/releases/{id}/steps` `{step, done}` |
| `PlanRestock` | `POST /api/restocks` `{supplierId, note?, eventId, leadDays}` → `{id}` |
| `ReviseRestock` / `CancelRestock` | `PUT` (same body) / `DELETE /api/restocks/{id}` |
| `TickRestock` | `PUT /api/restocks/{id}/ordered` `{ordered}` |
| `OrderRestock` | `PUT /api/restocks/{id}/supplier-order` `{supplierOrderId}` |

## UI

- **Paramètres › « Planning »**: the default steps (label, days, move up/down, remove, add) and « Prêt … jours avant l'événement », one save button.
- **Collection shelf** (`/designs`): « Planifier la sortie » when the collection has no release; otherwise its timeline (release day and event, each step with dates, a state badge and a checkbox) with « Modifier » and « Annuler la sortie ». The release form picks a date or an event and starts from the settings' steps (or the current steps).
- **Design** (`/designs/{id}`): its own release shown like on the shelf; when inherited, « Sortie de la collection X » (linking to the collection), its timeline read-only, with « Planifier ce design à part ».
- **Event page** (`/events/{id}`), « Préparation »: the ready-by day (« Prêt le 10 déc. · 3 jours avant »), the releases targeting the event (owner link, steps with dates and ticks), its restocks (order-by day, « Commandé », « Passer la commande », delete, and edit while the event is upcoming); a margin of 0 reads « le jour même » and « Préparer un réassort » with the event preset. The event form has an optional « Prêt … jours avant » (empty = workspace default).
- **Planning** (`/planning`, workshop menu after Créations), top to bottom:
  - **Summary strip**: « En retard · N » (danger, hidden at 0), « Cette semaine · N » (warning), « Prochaine échéance : … · date · dans N jours » (neutral); the counters jump to their agenda block. On phones the chips wrap.
  - **« Vue d'ensemble »**: a horizontal week timeline, no dependency arrows, no drag. Columns are weeks from the Monday of last week to the end of the week of the last upcoming item (at least 6 weeks); it scrolls horizontally inside a Reka ScrollArea with the lane labels kept visible, and draws an « Aujourd'hui » line; it opens scrolled so that today sits two days after the lane labels. One lane group per upcoming event (name and dates): its days as an accent bar, a « Prêt » flag on its ready-by day, a sub-row per release targeting it (steps as segments proportional to their days, coloured by state: done muted success, late danger, ongoing warning, upcoming neutral) and one sub-row per restock (« Commander chez <fournisseur> ») with a diamond on its order-by day (outlined neutral, danger when late, filled once ordered). A late step or restock that ended before the first column is drawn on that first column. Releases and legacy restocks on a plain date get their own lane. Segments and diamonds are focusable with a label and show a Reka Tooltip (label, dates, state). The timeline is a visual summary with an `aria-label`; the agenda is the accessible, actionable list.
  - **« À faire » agenda**: blocks « En retard » (only when non-empty), « Cette semaine » and « Plus tard » (collapsed, with its count, Reka Collapsible). Uniform rows: a date column with a relative label (« Aujourd'hui », « Demain », « dans 3 j », « il y a 2 j ») above the exact date (an upcoming step shows its first day, the day that files it in its block; an ongoing or late step its last day; a restock its order-by day); the title (step label, or « Commander chez <fournisseur> » + note) with its context (owner → event or date); the status pill and the tick checkbox (steps: done; restocks: « Commandé »); « Passer la commande » on restocks and « J 3/10 » on an ongoing multi-day step. Relative labels come from the payload's `today`, never the browser clock. On phones rows stack (date above title).
  - The former « Sorties à venir » and « Réassorts à venir » cards are gone (their content is in the timeline, the event page and the collection/design pages).
- **Planning header**: « Préparer un réassort » opens a form (supplier, note, an **event**, « Délai de commande » in days pre-filled from the supplier); the event page has the same button with the event pre-selected. The form loads the suppliers and events when it opens.
- **Restock rows**: a pending restock sits in the agenda next to the steps with a « Commandé » checkbox and « Passer la commande », which opens the supplier order drawer with that supplier (the restock is read once from the URL, `takeQuery`); saving the order links it to the restock (R10) in a second request: if the link fails (the supplier was changed in the drawer), the order stays placed and an error toast says why, and the restock can still be ticked by hand.
- **Dashboard**: the « Planning » card reuses the agenda row component for the late and this week's steps and pending restocks, with a link to `/planning`, hidden when both are empty.
