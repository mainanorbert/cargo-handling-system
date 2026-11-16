# Bulkstream cargo handling system: panel presentation guide

Use this guide to demonstrate the **implemented application** against the assessment brief. The current behaviour is described in [README.md](../README.md). [architecture.md](architecture.md) includes a broader target design with proposed ingestion and queue components; do not present those proposed components as already built.

## The story to tell in 30 seconds

> “This Laravel 13 and Filament 5 application follows cargo from vessel shipment, through a shore tank, to truck delivery. The Filament panel gives operators a clear workflow, while one cargo service owns stock calculations and validation. Each inbound discharge records its source volume, a 0.5% loss, and the volume received. The service replays events in event-time order and commits a valid movement and tank balance together under database locks. The movement and audit views let an operator trace what happened.”

The application is **one deployable Laravel service with a Filament control panel**. It has no queue consumer or external message-ingestion endpoint yet. Manual UI entries and callers of `CargoService` use the same processing core.

## Before the panel

1. Use a **fresh, separate demo database**. Configure it as described in [README.md](../README.md), then run `php artisan migrate --seed`, `npm run build`, and `php artisan serve`. Do not reset a database containing data you need.
2. Sign in to `/admin` with the local demo operator described in the README. The seeded data contains CPO, CPOL, PFAD, one vessel, one truck, **Tank A (100,000 L)**, **Tank B (200,000 L)**, and a **100,000 L CPO shipment**. It contains no initial movements. The local demo account is not seeded in production.
3. Have an editor open on [CargoService.php](../app/Services/CargoService.php), [RecordMovement.php](../app/Filament/Resources/Movements/Pages/RecordMovement.php), and [CargoTimelineTest.php](../tests/Feature/CargoTimelineTest.php). Keep the dashboard open in a browser.
4. Choose one past date in the application's configured timezone for all demo events. The times below use **10:00, 11:00, 11:30, 12:00, and 13:00 on that same date**. Enter the full date and time, and avoid relying on the form default. A fresh database makes the balances below reproducible.
5. Run the tests before the interview. The normal suite needs PHP SQLite PDO. The four concurrency tests additionally need a **dedicated disposable PostgreSQL database** and `pcntl`; SQLite passing is not evidence of PostgreSQL row locking. See [README.md](../README.md#tests).

## Live demonstration: UI action, expected result, and code to open

| Time | UI action | Expected result and what to say | Code to show |
| --- | --- | --- | --- |
| 0:00–0:45 | Open **Dashboard**. Point to **Tank stock**, **Available capacity**, **Tank status**, **Recent movements**, and **Exceptions**. | “The dashboard is the operator's starting point. Tank balances are current stock projections, and recent movements link them to the cargo flow.” Initially Tank A and Tank B are empty. | [CargoWorkflow widget](../app/Filament/Widgets/CargoWorkflow.php), [widget view](../resources/views/filament/widgets/cargo-workflow.blade.php), [panel provider](../app/Providers/Filament/AdminPanelProvider.php) |
| 0:45–1:30 | Under **Setup**, open **Commodities**, **Vessels**, **Tanks**, and **Trucks**. Under **Cargo workflow**, open **Shipments**. | Show CPO, CPOL, PFAD and CPO's temperature-control flag. Open the seeded CPO shipment. “Registration of a shipment records expected cargo; it does not change tank stock.” | [DatabaseSeeder](../database/seeders/DatabaseSeeder.php), [ShipmentResource](../app/Filament/Resources/Shipments/ShipmentResource.php), [cargo migration](../database/migrations/2026_11_03_113703_create_cargo_tables.php) |
| 1:30–3:00 | In **Inbound Cargo**, click the **Inbound Cargo** button to open the recording form. Select the seeded CPO shipment and **Tank A**. Enter **100,000 L** at **10:00**. Pause before saving to show the preview; then save. | Preview: **source 100,000 L; loss 500 L; received 99,500 L; projected Tank A stock 99,500 / 100,000 L**. After saving, Tank A has **99,500 L**, leaving **500 L** capacity. Open the shipment details to show the event's source, loss, and received quantities. | [reactive form and submission](../app/Filament/Resources/Movements/Pages/RecordMovement.php), [loss calculation and transaction](../app/Services/CargoService.php), [inbound details](../app/Filament/Resources/Movements/Pages/RecordVesselDischarge.php) |
| 3:00–3:45 | In **Shipments**, create a second **CPO** shipment for **1,000 L**. Use its generated reference, select **MV Palm Star**, and enter a point of origin. Return to **Inbound Cargo** and try to discharge its full 1,000 L into **Tank A** at **11:00**. | The preview shows **995 L** net receipt and a projected **100,495 L** balance. Saving produces an explicit rejection. Tank A stays at **99,500 L** and no movement is added. “The form warns early, but the custom capacity exception is enforced by the service inside the transaction.” | [TankCapacityException](../app/Exceptions/TankCapacityException.php), [capacity check and replay](../app/Services/CargoService.php), [error notification](../app/Filament/Resources/Movements/Pages/RecordMovement.php), [overflow test](../tests/Feature/CargoWorkflowTest.php) |
| 3:45–4:45 | In **Outbound Cargo**, click the **Outbound Cargo** button to open the recording form. Choose CPO, **Tank A**, the seeded truck, **10,000 L**, and **12:00**. Save. Then try a **90,000 L** delivery at **13:00**. | First delivery leaves **89,500 L** and records **zero inbound loss** on the outbound movement. The 90,000 L attempt shows an explicit insufficient-stock error and does not change the balance. “Outbound stock is deducted from available tank stock; the 0.5% rule applies only to inbound vessel flow.” | [outbound page](../app/Filament/Resources/Movements/Pages/RecordTruckDelivery.php), [outbound preview and error display](../app/Filament/Resources/Movements/Pages/RecordMovement.php), [stock check](../app/Services/CargoService.php), [delivery test](../tests/Feature/CargoWorkflowTest.php) |
| 4:45–6:00 | Return to **Inbound Cargo**. Try the second shipment's **1,000 L** discharge into Tank A at **11:00**, now *after* the 12:00 delivery was recorded. Then, in **Outbound Cargo**, record a **1,000 L** delivery at **11:30**. | The late inbound still fails: at 11:00 the historical stock would have been **100,495 L**, even though the current stock is only **89,500 L**. The late 11:30 delivery succeeds, and current stock becomes **88,500 L**. “Arrival order cannot override event time. We validate the full affected timeline before committing.” | [recordBatch and replay](../app/Services/CargoService.php), [tie-break comparison](../app/Services/CargoService.php), [late-event tests](../tests/Feature/CargoTimelineTest.php) |
| 6:00–7:00 | Open **Reports** and **Audits**. Find the inbound and outbound movements by event reference, shipment, tank, or time. Return to the dashboard to show the final balance and recent movements. | Point to **event time** versus **recorded/received time**, source versus received volume, truck, tank, commodity, and operator. “The movement history is the evidence behind the current balance; the audit trail records who changed entities.” | [MovementReportResource](../app/Filament/Resources/Reports/MovementReportResource.php), [MovementResource](../app/Filament/Resources/Movements/MovementResource.php), [AuditObserver](../app/Observers/AuditObserver.php), [MovementObserver](../app/Observers/MovementObserver.php) |

### When showing the overflow and stock errors

Let the panel read the message. Point back to the tank balance after each rejected submission. For a domain failure, the form shows an inline error and a persistent **Movement rejected** notification with the reason. For a form-level quantity limit, Filament shows a validation error; the service still enforces the underlying rule when called directly or after data changes. Rejected submissions are **not stored as separate failed-event records**. The dashboard's **Exceptions** count refers to existing `needs_review` records, not every rejected form attempt.

## Five code decisions to explain

### 1. The service is the authoritative processing boundary

[RecordMovement.php](../app/Filament/Resources/Movements/Pages/RecordMovement.php) gathers input and calls `CargoService::record()`. The service also exposes `recordBatch()` for callers supplying a shuffled batch. [CargoService.php](../app/Services/CargoService.php) validates quantities, applies loss, checks shipment and tank limits, replays history, and persists the result. An API or queue consumer could call this same service later; neither is currently implemented.

Say: **“A reactive preview is useful to the operator, but it can become stale. The service recalculates and validates under database locks before any stock change is committed.”**

### 2. Fixed units and auditable loss

Inputs are litres with up to three decimal places; balances are stored as **integer millilitres**. `CargoService::inboundQuantities()` applies **50 basis points = 0.5%** and rounds the loss **half up to the nearest millilitre for each inbound event**. It records `source_ml`, `loss_ml`, `quantity_ml` (received), and `loss_basis_points`. The 100,000 L example yields 100,000 L source, 500 L loss, and 99,500 L received. A delivery records zero inbound loss.

The brief states a **mass** loss and a **volume** capacity, without density or temperature readings. Explain that applying 0.5% to litres is an assessment simplification, **not a physically exact mass-to-volume conversion**. Do not claim the CPO flag operates heaters or sensors.

### 3. Capacity, commodity, and stock invariants

In `replay()`, every inbound step checks the **received** quantity against the tank's remaining capacity and throws [TankCapacityException](../app/Exceptions/TankCapacityException.php) on overflow. Every delivery checks available stock. A non-empty tank rejects a different commodity; an empty tank can take another commodity. Zero, negative, and overly precise quantities are rejected. Shipment allocations across tanks cannot exceed the shipment's source quantity.

The tank's balance is a projection of applied movement history. The service checks that the stored projection agrees with replayed history before accepting another event.

### 4. Event-time ordering and atomic concurrency protection

Each movement stores `occurred_at` and `received_at`. The service sorts by occurrence time and then **bytewise event reference** for a deterministic tie. For a late event, it replays the entire affected tank history with the new event. Any historical capacity, stock, or commodity conflict rejects the operation with no partial balance change. The old movements retain their recorded quantities; valid late events are appended with their original event time.

`DB::transaction()` wraps the operation. `lockForUpdate()` locks affected tanks and shipments in ascending ID order. The unique database constraint on `movements.event_reference` closes duplicate races, while shipment locks prevent concurrent allocations to different tanks from exceeding one shipment. Show [CargoConcurrencyTest.php](../tests/Feature/CargoConcurrencyTest.php) as the PostgreSQL test design; state its actual execution result accurately.

### 5. Filament UI, lifecycle hooks, and visibility

Filament's `live()` fields update shipment defaults, compatible tank choices, and the loss and balance previews. The [MovementObserver](../app/Observers/MovementObserver.php) writes a secondary log entry **after commit**. The [AuditObserver](../app/Observers/AuditObserver.php) records entity changes and actor context; rolled-back changes do not leave audit rows. Neither observer owns tank arithmetic. The [dashboard widget](../app/Filament/Widgets/CargoWorkflow.php) reads stock, tank status, exceptions, and recent movements for operators.

## Evidence to have ready

| Claim | Test file and example |
| --- | --- |
| 100,000 L inbound becomes 99,500 L; loss is recorded; outbound has no inbound loss | [CargoWorkflowTest.php](../tests/Feature/CargoWorkflowTest.php) — `test_inbound_discharge_applies_half_percent_loss_before_delivery` |
| Loss rounding and exact capacity | [CargoWorkflowTest.php](../tests/Feature/CargoWorkflowTest.php) — `test_inbound_loss_rounds_half_up_to_a_millilitre_and_exact_received_capacity_is_allowed` |
| Overflow, invalid quantities, duplicates, commodity mismatch, and visible form errors | [CargoWorkflowTest.php](../tests/Feature/CargoWorkflowTest.php) |
| Shuffled batch, valid late event, historical conflicts, and rollback | [CargoTimelineTest.php](../tests/Feature/CargoTimelineTest.php) |
| Competing receipts, duplicate events, shipment allocations, and deliveries on PostgreSQL | [CargoConcurrencyTest.php](../tests/Feature/CargoConcurrencyTest.php) |
| Operator-facing reports and audit history | [CargoReportTest.php](../tests/Feature/CargoReportTest.php), [AuditTrailTest.php](../tests/Feature/AuditTrailTest.php) |

Run `php artisan test --compact` when PHP SQLite PDO is installed, and `vendor/bin/pint --dirty --format agent` for PHP formatting. The most recent full PHPUnit run in this workspace passed **54 tests**, with **four PostgreSQL concurrency tests skipped** because an isolated PostgreSQL test database was unavailable. Before claiming concurrency was tested on PostgreSQL, run those four against a dedicated disposable database using the command in the README.

## Likely panel questions: short answers

**Why a Laravel service instead of calculations in Filament?** The service is the authoritative rule boundary and can be reused by UI, jobs, or a future API. Filament handles presentation and reactive guidance.

**Why does a shipment say 100,000 L while the tank receives 99,500 L?** The shipment tracks source cargo. Each inbound event records 0.5% loss before crediting its tank; both values remain traceable.

**What if two operators target the same remaining capacity?** The transaction serializes competing tank updates with a row lock. The second operation validates against the committed result and either fits or fails. A unique event reference prevents duplicate application.

**What if a source event arrives late?** The service sorts the candidate timeline by occurrence time and reference, validates every step, and commits only a valid result. A conflicting late event is rejected; current balance and movement history remain unchanged.

**What is still limited?** There is no external message consumer or durable failed-event intake record; the dashboard does not represent every rejected form attempt. CPO temperature control is a data flag, not a hardware integration. Full replay reads the tank's applied history on each operation, which is suitable for assessment scale but would need a throughput strategy at very large scale. Panel access is authenticated, with no distinct operator roles yet.

## Closing line

> “The demonstration shows the three required stages, the exact 0.5% inbound adjustment, a rejected overflow, a rejected over-delivery, and a late event checked against historical stock. The UI makes those decisions visible, while the service, transaction, locks, and tests make them enforceable.”
