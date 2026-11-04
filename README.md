# Bulkstream Cargo Handling System

An authenticated Laravel 13 / Filament 5 application for registering inbound shipments, recording vessel discharges into shore tanks, and recording truck deliveries.

## Setup

Requires PHP 8.5 with the extensions required by Laravel, Composer, Node.js, and PostgreSQL. PostgreSQL is recommended for production because stock processing relies on row locks. Install dependencies with `composer install` and `npm install`, copy `.env.example` to `.env`, set `APP_KEY` with `php artisan key:generate`, and configure the PostgreSQL connection in `.env`. Then run:

```sh
php artisan migrate --seed
npm run build
php artisan serve
```

In the local environment the seeder creates `demo@example.com` with password `password`. This account is for local demonstrations only; it is not seeded in production. Open `/admin` and sign in. Production operators are created directly in PostgreSQL as described below. Never use the demo credentials in production.

## Deploy to Render

The root `render.yaml` provisions a Docker web service and PostgreSQL database in Render's Frankfurt region. In Render, choose **New → Blueprint**, connect this repository, and select its deployment branch. When prompted, provide only:

- `APP_KEY`: output from `php artisan key:generate --show`

The first deployment migrates and seeds the database. It does not create an administrator or accept administrator credentials as environment variables. Later pushes to the connected branch automatically rebuild and deploy the root `Dockerfile`; startup runs outstanding migrations before Apache begins serving the application. Render supplies `APP_URL` through `RENDER_EXTERNAL_URL`, and the container listens on Render's `PORT`. The `/up` endpoint is used for health checks.

Create a production operator from the PostgreSQL terminal after the first deployment. Laravel requires a `$2y$` bcrypt hash; PostgreSQL's `pgcrypto` produces the compatible `$2a$` form, so the query converts its prefix before storing it. Replace the three placeholders, use a unique password of at least eight characters, and keep the password out of shell history where possible.

```sql
CREATE EXTENSION IF NOT EXISTS pgcrypto;

INSERT INTO users (name, email, password, created_at, updated_at)
VALUES (
    'Operator name',
    'operator@example.com',
    overlay(crypt('replace-with-a-unique-password', gen_salt('bf', 12)) placing '2y' from 2 for 2),
    NOW(),
    NOW()
);
```

The email address must be unique. To reset an existing operator password, run:

```sql
UPDATE users
SET password = overlay(crypt('replace-with-a-unique-password', gen_salt('bf', 12)) placing '2y' from 2 for 2),
    updated_at = NOW()
WHERE email = 'operator@example.com';
```

The Blueprint uses Render's free web and PostgreSQL plans for demonstration. Free PostgreSQL databases expire after 30 days; choose paid plans for a durable deployment. Container-local files are ephemeral, so configure durable object storage before adding user uploads.

## Walkthrough

1. In **Inbound shipments**, create a shipment at its point of origin with a reference, vessel, commodity, and expected litres. This does not add stock.
2. Open **Record vessel discharge** from the sidebar and click its **Record vessel discharge** button. Select the shipment and tank and enter the actual discharged litres and event time. The same shipment may be split across several tanks. Selecting it defaults the quantity to what remains, and the server prevents all of its tank allocations from exceeding the original shipment quantity. The event reference is generated automatically and remains stable when retrying the form after validation errors. The shipment table has one summary row per shipment, including original quantity, offloaded quantity, remaining quantity, tank allocations and completion percentage.
3. Review tank balances and utilisation on the dashboard or in **Tanks**. Open **Record truck delivery** from the sidebar and click its **Record truck delivery** button. Select a tank and truck and enter the actual litres delivered.
4. The inbound page shows one searchable progress row per shipment, with a details modal for its tank-level events. The outbound page shows individual delivery movements. Both support 10, 25, or 50 rows per page; there is no duplicate combined **Cargo movements** screen. Valid late events are applied after the complete tank timeline is checked; an invalid historical capacity, stock, or commodity transition is rejected with no balance changes.

Manage vessels, trucks, commodities, tanks and shipments from their labelled sections. Shipment editing and deletion are disabled once a movement references it; movement history is immutable. Referenced records cannot be deleted due to database constraints.

## Rules and limitations

Quantities are stored as integer millilitres and balance calculations do not use floating-point arithmetic. The current operating rule does not automatically deduct 0.5% from each tank allocation. The entered discharge is the quantity credited to that tank, and cumulative discharge is compared with the shipment's original quantity. The 0.5% is the allowed shortfall: a cumulative result from 99.5% through 100% is shown in green, while a partial result below 99.5% is shown in red but remains open for further tank allocations. More than 100% is rejected. The Tank allocations column lists the tanks used; the shipment details view shows each exact discharged quantity. Any shortfall is an operational variance only: without density and temperature data it is not represented as an exact physical mass-to-volume conversion. A tank is assumed to contain one commodity at a time. CPO is marked as requiring temperature control, but no physical monitoring or control integration is provided.

`CargoService::record()` processes a single event; `recordBatch()` accepts a batch of source events, including shuffled events. Both lock all affected tank rows and shipment rows in ascending ID order inside one transaction. Shipment locks serialize allocations to different tanks so concurrent workers cannot collectively exceed the shipment's original quantity. The service sorts the complete applied tank history plus the incoming events by occurrence time and then by bytewise event-reference order, and validates every historical capacity, stock and commodity transition before persisting any changes. Times are normalized to the application timezone (UTC by default) at whole-second precision. For tied timestamps the reference defines a deterministic order, not a claim about physical sequence.

New tanks start empty. Replay reconstructs stock from recorded movement quantities and does not recalculate or rewrite historical movements. Records created under the earlier automatic-loss rule retain their recorded quantities and loss fields for audit purposes. A valid late event is appended with its original event time and current receipt time. A late delivery gets its commodity from the historical tank contents. Any conflict rejects the entire operation or batch. A mismatch between the current balance and its applied history also blocks processing. Unique event references prevent duplicate application; callers must retain their source identifier when retrying. Duplicate submissions are rejected, including database uniqueness races across different tanks.

The existing `needs_review` records from earlier versions remain excluded from applied stock and require operator investigation; this change does not automatically apply them. Rejected input is shown to the operator and is not retained as a separate failed-event record. There is no queue consumer or external ingestion endpoint yet: integrations can call the service with stable source identifiers. Full replay reads a tank's applied history on each operation, which favors assessment correctness over throughput for very large histories. The movement observer writes a log entry after commit. Every authenticated panel user has access to all operations.


## Tests

Run `php artisan test --compact` with the SQLite PDO extension installed for the normal workflow tests. The concurrency suite requires PostgreSQL and the PHP `pcntl` and `posix` extensions; it skips on SQLite. Use a dedicated disposable PostgreSQL database, because these tests migrate and reset it:

```sh
DB_CONNECTION=pgsql DB_HOST=127.0.0.1 DB_PORT=5432 DB_DATABASE=cargo_test DB_USERNAME=cargo_test DB_URL='' vendor/bin/phpunit tests/Feature/CargoWorkflowTest.php tests/Feature/CargoTimelineTest.php tests/Feature/CargoConcurrencyTest.php
```

Supply the test database password through your local environment. The concurrency tests use separate processes and connections, hold competing workers at actual PostgreSQL row locks, and verify inbound capacity, outbound stock allocation, and duplicate-event rollback. Format PHP with `vendor/bin/pint --dirty --format agent`.
