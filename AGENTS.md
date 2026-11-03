<laravel-boost-guidelines>
=== foundation rules ===

# Laravel Boost Guidelines

## Foundational Context

This application is a Laravel application running on PHP 8.5. Always use the APIs that match the installed major version of each package — do not assume a version.

Before relying on a package's API, confirm its installed version:
- PHP packages: run `composer show --direct` to list direct dependencies with versions, or `composer show <vendor/package>` for a single package.
- JS packages: check `package.json` for the installed versions.

## Skills Activation

This project has domain-specific skills available in `**/skills/**`. You MUST activate the relevant skill whenever you work in that domain—don't wait until you're stuck.

## Conventions

- You must follow all existing code conventions used in this application. When creating or editing a file, check sibling files for the correct structure, approach, and naming.
- Use descriptive names for variables and methods. For example, `isRegisteredForDiscounts`, not `discount()`.
- Check for existing components to reuse before writing a new one.

## Verification Scripts

- Do not create verification scripts or tinker when tests cover that functionality and prove they work. Unit and feature tests are more important.

## Application Structure & Architecture

- Stick to existing directory structure; don't create new base folders without approval.
- Do not change the application's dependencies without approval.

## Frontend Bundling

- If a frontend change doesn't show in the UI or you get a "Unable to locate file in Vite manifest" error, run `npm run build` or ask the user to run `npm run dev` or `composer run dev`.

## Documentation Files

- You must only create documentation files if explicitly requested by the user.

=== boost rules ===

# Laravel Boost

## Tools

- Laravel Boost is an MCP server with tools designed specifically for this application. Prefer Boost tools over manual alternatives like shell commands or file reads.
- Use `database-query` to run read-only queries against the database instead of writing raw SQL in tinker.
- Use `database-schema` to inspect table structure before writing migrations or models.
- Use `get-absolute-url` to resolve the correct scheme, domain, and port for project URLs. Always use this before sharing a URL with the user.
- Use `browser-logs` to read browser logs, errors, and exceptions. Only recent logs are useful, ignore old entries.

## Searching Documentation (IMPORTANT)

- Use `search-docs` before changes that depend on Laravel ecosystem APIs, behavior, configuration, or version-specific syntax. Skip it for copy-only edits and other changes where package documentation is irrelevant. Reuse sufficient results already in context instead of searching again.
- Pass a `packages` array to scope results when you know which packages are relevant.
- Use multiple broad, topic-based queries: `['rate limiting', 'routing rate limiting', 'routing']`. Expect the most relevant results first.
- Do not add package names to queries because package info is already shared. Use `test resource table`, not `filament 4 test resource table`.

### Search Syntax

1. Use words for auto-stemmed AND logic: `rate limit` matches both "rate" AND "limit".
2. Use `"quoted phrases"` for exact position matching: `"infinite scroll"` requires adjacent words in order.
3. Combine words and phrases for mixed queries: `middleware "rate limit"`.
4. Use multiple queries for OR logic: `queries=["authentication", "middleware"]`.

## Project Rules

- This project contains committed, area-grouped rules in `.ai/rules` when that directory exists, including path-scoped framework guidelines under `.ai/rules/boost`. Before you enter plan mode or create/edit any file, you MUST first: open @.ai/rules/index.md (it maps file globs to rule files), read every rule file whose globs cover the path(s) in scope, and run `grep -rin 'keyword' .ai/rules` to catch what a path match alone misses. Do not write code until you have read and are following every matching rule. If `.ai/rules` does not exist, continue without it.

## Artisan

- Run Artisan commands directly via the command line (e.g., `php artisan route:list`). Use `php artisan list` to discover available commands and `php artisan [command] --help` to check parameters.
- Inspect routes with `php artisan route:list`. Filter with: `--method=GET`, `--name=users`, `--path=api`, `--except-vendor`, `--only-vendor`.
- Read configuration values using dot notation: `php artisan config:show app.name`, `php artisan config:show database.default`. Or read config files directly from the `config/` directory.

## Tinker

- Execute PHP in app context for debugging and testing code. Do not create models without user approval, prefer tests with factories instead. Prefer existing Artisan commands over custom tinker code.
- Always use single quotes to prevent shell expansion: `php artisan tinker --execute 'Your::code();'`
  - Double quotes for PHP strings inside: `php artisan tinker --execute 'User::where("active", true)->count();'`

=== php rules ===

# PHP

- Always use curly braces for control structures, even for single-line bodies.
- Use PHP 8 constructor property promotion: `public function __construct(public GitHub $github) { }`. Do not leave empty zero-parameter `__construct()` methods unless the constructor is private.
- Use explicit return type declarations and type hints for all method parameters: `function isAccessible(User $user, ?string $path = null): bool`
- Use TitleCase for Enum keys: `FavoritePerson`, `BestLake`, `Monthly`.
- Prefer PHPDoc blocks over inline comments. Only add inline comments for exceptionally complex logic.
- Use array shape type definitions in PHPDoc blocks.

=== deployments rules ===

# Deployment

- Laravel can be deployed using [Laravel Cloud](https://cloud.laravel.com/), which is the fastest way to deploy and scale production Laravel applications.
- Activate the `deploying-to-cloud` skill whenever deploying to Laravel Cloud, configuring Cloud environments or resources, using the Cloud CLI, or troubleshooting Cloud deployments.

=== laravel/core rules ===

# Do Things the Laravel Way

- Use `php artisan make:` commands to create new files (i.e. migrations, controllers, models, etc.). You can list available Artisan commands using `php artisan list` and check their parameters with `php artisan [command] --help`.
- If you're creating a generic PHP class, use `php artisan make:class`.
- Pass `--no-interaction` to all Artisan commands to ensure they work without user input. You should also pass the correct `--options` to ensure correct behavior.

### Model Creation

- When creating new models, create useful factories and seeders for them too. Ask the user if they need any other things, using `php artisan make:model --help` to check the available options.

## APIs & Eloquent Resources

- For APIs, default to using Eloquent API Resources and API versioning unless existing API routes do not, then you should follow existing application convention.

## URL Generation

- When generating links to other pages, prefer named routes and the `route()` function.

## Testing

- When creating models for tests, use the factories for the models. Check if the factory has custom states that can be used before manually setting up the model.
- Faker: Use methods such as `$this->faker->word()` or `fake()->randomDigit()`. Follow existing conventions whether to use `$this->faker` or `fake()`.
- When creating tests, make use of `php artisan make:test [options] {name}` to create a feature test, and pass `--unit` to create a unit test. Most tests should be feature tests.

=== pint/core rules ===

# Laravel Pint Code Formatter

- If you have modified any PHP files, you must run `vendor/bin/pint --dirty --format agent` before finalizing changes to ensure your code matches the project's expected style.
- Do not run `vendor/bin/pint --test --format agent`, simply run `vendor/bin/pint --format agent` to fix any formatting issues.

=== phpunit/core rules ===

# PHPUnit

- This project uses PHPUnit. Create tests with `php artisan make:test --phpunit {name}`.
- Do not include the test suite directory in `{name}`. Use `SomeFeatureTest`, not `Feature/SomeFeatureTest`.
- Read the `testing-best-practices` skill for guidance on coverage, naming, structure, dependency isolation, and review.

## Running Tests

- Run the narrowest set of tests that covers the change. Pass a file path or `--filter=testName` to `php artisan test --compact`.
- Rerun a test after each change to it.
- Run `vendor/bin/phpunit` to call the test runner directly. It accepts the same file path and `--filter=testName` arguments.

</laravel-boost-guidelines>

# Bulkstream Cargo Handling System — Codex Instructions

## Purpose and stack

Build an assessment-ready Laravel and FilamentPHP application for the workflow: vessel discharge → shore tank storage → truck delivery. Deliver working functionality, clear documentation and tests; prefer a small modular application over unnecessary infrastructure. Follow existing project conventions and use compatible, supported package versions.

## Working practices

- Inspect relevant existing code, configuration and tests before changes. Preserve unrelated work and avoid replacing working features without reason.
- Before creating or updating any file, inspect the applicable instructions and directory structure, then review relevant sibling files and existing examples. Place files in the established location and follow the repository's naming, formatting and organizational conventions; do not introduce a new structure when an established one applies.
- Implement business rules in focused application/domain services shared by Filament, jobs and any API. Models define relationships and persistence; Filament handles presentation and input, not authoritative cargo calculations.
- Use Laravel conventions, migrations, validation, typed code where appropriate, and meaningful names. Avoid unnecessary abstractions, dependencies and speculative features.
- When requirements are ambiguous, make the smallest reasonable assumption, document it in the README and do not present it as a brief requirement. Report unfinished work or failed checks honestly.

## Domain and mandatory rules

- Support Crude Palm Oil (CPO), Crude Palm Olein (CPOL), and Palm Fatty Acid Distillate (PFAD). Seed their descriptions and CPO's temperature-control requirement. Do not claim physical temperature control without a real integration; do not invent safety thresholds.
- Represent vessels, tanks, trucks and cargo movements. Record source/event reference, commodity, tank, event time, quantities and processing status. An operator must be able to trace receipts and deliveries.
- A tank's balance must remain between zero and its stated capacity. Reject an inbound movement that would exceed capacity with a custom domain exception. Reject outbound delivery exceeding available stock. Reject zero, negative or invalid quantities.
- Apply a 0.5% loss to every inbound vessel flow before checking capacity and updating stock. Record source quantity, applied loss, received quantity and loss rate. Do not apply this specified inbound loss to truck deliveries.
- The brief describes mass loss and volume capacity without density or temperature data. For the assessment, use litres consistently and explicitly document the simplifying assumption that the 0.5% mass-loss rate is applied to volume. Do not represent it as an exact physical conversion. Use fixed-precision decimal or integer units, a documented rounding rule, and no floating-point arithmetic for balances.
- Assume a tank contains one commodity at a time; reject incompatible receipts until it is empty. Document this as an assumption, not a stated assessment rule.

## Ordering, atomicity and audit

- Store both event occurrence time and receipt time. Process events in chronological event-time order, with a deterministic tie-breaker. Protect processing for each affected tank from concurrent workers.
- Do not silently apply an event older than an already applied movement. Implement a controlled reconciliation/replay that validates the entire affected timeline and atomically applies a valid result, or flags conflicts for operator review. If replay is not completed, flag late events for reconciliation, keep balances unchanged and explicitly disclose this limitation; do not claim full late-event support.
- Update tank balances and create movement records in a single database transaction. Lock the affected tank row before reading and validating its balance; ensure exceptions roll back all changes. Do not rely on frontend validation for correctness.
- Require a stable source event identifier and enforce uniqueness in the database so retries or duplicate messages cannot create duplicate movements. Use database constraints and foreign keys where appropriate. Preserve movement history; corrections must be traceable rather than silently rewriting records.

## Filament dashboard and hooks

- Provide authenticated access and a clear operations dashboard with tank balances and utilisation, stock by commodity, recent movements, and failed or late events.
- Provide practical views/forms for tanks, inbound vessel discharges and outbound truck deliveries, plus searchable movement/event history. Show units, source and received quantities, loss, capacity or available stock, event times and useful errors.
- Use reactive Filament handlers to preview loss and projected balances. Recalculate and validate on the server inside the transaction because previews can become stale.
- Demonstrate a suitable backend entity lifecycle hook or observer for a secondary concern such as audit logging or dispatching an event after commit. Keep transaction and balance rules in the service layer; document the hook's purpose.
- Use the provisional, brand-inspired palette: dark teal #004D4D, teal accent #008080, white #FFFFFF, light grey #F4F6F6 and dark grey #263238. These are not verified official brand codes. Ensure readable contrast, responsive layouts and accessible labels; use distinct status colours with text, not colour alone.

## Verification

- Test the full inbound and outbound workflows; 100,000 L inbound yields 99,500 L under the documented assumption. Cover exact capacity, overflow exception and rollback, insufficient stock, invalid input, commodity compatibility and duplicate events.
- Test shuffled event order, deterministic tie-breaking and an event arriving after later events were applied; verify reconciliation or the documented safe flagging behaviour. Include a conflict case and ensure no partial balance changes.
- Test concurrent competing operations using the intended production database, such as PostgreSQL, with separate connections or processes. Verify capacity cannot be overallocated and duplicate events cannot be applied twice. Do not present SQLite-only tests as proof of row-lock behaviour.
- Test the critical Filament flows and displayed errors. Run the relevant tests and formatter/linter supported by the repository; report commands and results. Do not claim checks passed if they were not run.

## Delivery

- Provide a README with prerequisites, installation, environment configuration, migrations, seeders, run/test commands, demo login instructions, an end-to-end walkthrough, architecture, assumptions and known limitations. Never commit real credentials or secrets.
- Seed representative vessels, trucks, tanks and all three commodities, with realistic demo data. Make the loss calculation, overflow rejection and event history easy for an assessor to demonstrate.
- Provide a reproducible repository and, if feasible, an accessible live demo. Document deployment and any demo limitations. Do not deploy or publish without the user's instruction.
