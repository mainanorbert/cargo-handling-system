# Bulkstream Cargo Handling System

An authenticated Laravel 13 / Filament 5 application for registering inbound shipments, recording vessel discharges into shore tanks, and recording truck deliveries.

## Setup

Requires PHP 8.5 with the extensions required by Laravel, Composer, Node.js, and PostgreSQL. PostgreSQL is recommended for production because stock processing relies on row locks. Install dependencies with `composer install` and `npm install`, copy `.env.example` to `.env`, set `APP_KEY` with `php artisan key:generate`, and configure the PostgreSQL connection in `.env`. Then run:

```sh
php artisan migrate --seed
npm run build
php artisan serve
```

In the local environment the seeder creates `demo@example.com` with password `password`. This account is for local demonstrations only; it is not seeded in production. Open `/admin` and sign in. Create a production operator with `php artisan make:filament-user`. Never use the demo credentials in production.

## Walkthrough

1. In **Inbound shipments**, create a shipment at its point of origin with a reference, vessel, commodity, and expected litres. This does not add stock.
2. In **Cargo movements**, choose **Record vessel discharge**, select the shipment and tank, and enter the actual discharged litres, event time, and unique event reference. The preview estimates loss and balance; the server checks the current balance again on submission. The demo shipment of 100,000 L yields 99,500 L and fits in Tank A.
3. Review tank balances and utilisation on the dashboard or in **Tanks**. In **Cargo movements**, choose **Record truck delivery**, select a tank and truck, and enter the actual litres delivered.
4. Search movement history by event or shipment reference. Late events are labelled **Needs review** and do not change stock.

Manage vessels, trucks, commodities, tanks and shipments from their labelled sections. Shipment editing and deletion are disabled once a movement references it; movement history is immutable. Referenced records cannot be deleted due to database constraints.

## Rules and limitations

Quantities are stored as integer millilitres. The specified 0.5% mass-loss rate is applied to volume as an assessment simplification, not a physical conversion; loss is rounded to the nearest millilitre, with halves rounded up. No density or temperature conversion is implied. A tank is assumed to contain one commodity at a time. CPO is marked as requiring temperature control, but no physical monitoring or control integration is provided.

The service validates each event in a transaction, locks the tank before checking stock, and records a unique event reference. Applied movements are ordered by event time and, for ties, by event reference. An event that sorts before an already applied event is recorded for operator review without changing stock. Automatic replay and a review-resolution workflow are not implemented; operators must investigate and use a controlled external correction process. Events are processed on entry rather than queued and sorted as a batch. A late outbound event cannot be registered against a currently empty tank because its commodity cannot be inferred. Failed validation is shown to the operator and is not retained as an event. The movement observer writes a log entry after commit. There are no role-specific permissions: every authenticated panel user has access to all operations.

## Tests

Run `php artisan test --compact` with the SQLite PDO extension installed. To exercise PostgreSQL, use a dedicated empty test database and override the PHPUnit database settings for that connection. SQLite does not prove PostgreSQL row-lock behaviour; concurrent worker tests are not yet supplied. Format PHP with `vendor/bin/pint --dirty --format agent`.
