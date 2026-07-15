# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What this is

A Tier-1 FBR (Federal Board of Revenue, Pakistan) classic IMS POS integration — **not** the SRO 709 Digital Invoicing scheme. Three independent services in this repo:

- **`backend/`** — Laravel 12 (PHP 8.2+) API: sales, inventory, customers, and the FBR fiscalization pipeline. PostgreSQL + Redis.
- **`frontend/`** — React 19 + TypeScript + Vite PWA: the cashier checkout screen, with its own offline layer (IndexedDB) independent of the backend's own offline layer.
- **`print-agent/`** — standalone Node/Express service that runs locally on each till, receives structured receipt JSON from the backend, and drives an 80mm ESC/POS thermal printer directly (renders the FBR QR/logo itself — the backend never sends images or ESC/POS bytes).

`backend_failed_l11_scaffold/` is a dead, abandoned first attempt — ignore it; it is gitignored and not part of the app.

See `TEST_REPORT.md` at the repo root for the results (and known-unverified items) of the most recent sandbox resilience testing pass — read it before assuming FBR network behavior is verified.

## Commands

### Backend (`backend/`)

```bash
composer install
cp .env.example .env && php artisan key:generate
php artisan migrate                 # DB must exist; see Testing note below
php artisan db:seed                 # roles/permissions + demo data
php artisan serve                   # or: composer run dev (serve + queue:listen + pail + vite, concurrently)
```

Tests (full backend suite, real Postgres — **not** sqlite/in-memory):
```bash
php artisan test                              # all tests
php artisan test --filter=test_method_name    # single test by name
php artisan test tests/Feature/Sales/CreditInvoiceTest.php   # single file
```
`phpunit.xml` points `DB_DATABASE` at `pos_fiscal_test` — that database must exist and be migrated (`php artisan migrate --env=testing`) before running tests; there is no sqlite fallback.

Other useful commands:
```bash
php artisan usin:allocate {terminal_id}                          # allocate+print one USIN (manual/debug)
php artisan usin:concurrency-test {terminal_id} --n=30 --delay-ms=25   # gapless/uniqueness stress test
php artisan fiscal:sweep-outbox                                  # crash-recovery reclaim (normally scheduled every minute)
php artisan fiscal:check-pending-age                              # compliance breach check (normally scheduled every 10 min)
php artisan fiscal:sandbox-smoke-test                             # posts sample invoices to the configured FBR sandbox
vendor/bin/pint                                                   # Laravel Pint formatter
```

### Frontend (`frontend/`)

```bash
npm install
npm run dev        # vite dev server
npm run build       # tsc -b && vite build - build fails on type errors, this IS the typecheck
npm run lint         # oxlint
```
No automated test suite exists for the frontend (no vitest/jest configured) — verify UI changes by running the dev server and driving the flow in a browser.

### Print agent (`print-agent/`)

```bash
npm install
cp config.example.json config.json   # then edit printer.interface / printer.type / fbrLogoPath
npm start                             # listens on :9100 by default (PRINT_AGENT_PORT env var overrides)
```
No automated test suite. `printer.interface: "tcp://<ip>"` needs no native deps; `"printer:<name>"` (OS spooler, for USB printers) requires `npm install printer`, which needs native build tools.

### Full stack via Docker

`docker-compose.yml` at repo root brings up `app` (PHP-FPM, via `docker/php/Dockerfile`) + `nginx` + `postgres` + `redis`. Inside the `app` container, `supervisord` (`docker/supervisor/supervisord.conf`) runs four long-lived processes: `php-fpm`, a `default` queue worker, **two** dedicated `fiscal` queue workers, and a `schedule:run` loop. The print-agent and frontend are not containerized — run them separately per above.

## Architecture

### The fiscal pipeline is the core of this app

A sale is a two-phase commit, deliberately split so FBR reachability is never on the checkout critical path:

1. **`CheckoutService::checkout()`** (`app/Services/Sales/CheckoutService.php`) — validates the cart, computes exact decimal totals (`SaleTotalsCalculator`, all `bcmath`), allocates a USIN, and inserts the `Invoice` + `InvoiceItem` rows + a `fiscal_outbox` row **all in one DB transaction**. The invoice is created with `fiscal_status='pending'` and the sale is considered complete (printable) at this point — no HTTP call to FBR has happened yet.
2. Only **after that transaction commits** is `FiscalizeInvoiceJob` dispatched onto a dedicated `fiscal` queue. `ReturnService::createReturn()` follows the identical pattern for credit invoices (FBR `InvoiceType=3`).

`FiscalSubmissionService::submit()` (`app/Services/Fiscal/FiscalSubmissionService.php`) is the single place that actually talks to FBR, used by both the queue worker and the sandbox smoke-test command so behavior never diverges. It runs in three deliberately-separate phases — **claim** (short transaction, locks the outbox row `FOR UPDATE`, flips `pending`→`processing`), **submit** (the actual HTTP call, held with *no* DB lock so a slow/unreachable endpoint never exhausts the connection pool), **record** (short transaction, logs the attempt and sets the final state). A worker that dies mid-`submit` leaves a row stuck `processing`; `SweepFiscalOutboxCommand` (scheduled every minute) reclaims anything stuck past 120s and re-dispatches it — this is a second, independent safety net on top of the same staleness check `claim()` itself performs.

Retry timing is **not** governed by Laravel's queue backoff — `FiscalizeInvoiceJob` has a high `$tries` purely as a safety ceiling. The real exponential backoff (`min(base * 2^(attempt-1), max)`) and the give-up threshold (`fiscal.max_retry_attempts`) live in `fiscal_outbox`/`FiscalSubmissionService`, because that state has to be dashboard-visible and survive across job/worker restarts.

**Known limitation, not yet resolved:** the classic FBR PostData contract, as implemented here, sends no idempotency/dedup key to FBR. If a real network timeout occurs *after* FBR has already accepted a submission server-side, a retry will resubmit an identical payload with no way for us to detect that FBR already has it. See `TEST_REPORT.md` (item B10) before treating this as solved.

### USIN generation

`UsinGenerator::next()` (`app/Services/Fiscal/UsinGenerator.php`) allocates strictly sequential, gapless, per-terminal invoice numbers via a locked counter row (`usin_counters`, one row per terminal, `SELECT ... FOR UPDATE`) **inside the caller's existing transaction** — it throws if called outside one. This is deliberately not a native Postgres `SEQUENCE`: `nextval()` doesn't roll back, which would violate gaplessness whenever a sale aborts after allocating a number. The locked-row pattern makes the counter commit-or-rollback atomically with the invoice itself.

### Fiscalizer strategy (adapter) pattern

`FiscalizerFactory::forTerminal()` (`app/Services/Fiscal/FiscalizerFactory.php`) is the only place that maps a terminal's resolved mode to a concrete adapter — `FbrPostDataFiscalizer` (used for both `fbr_cloud` and `fbr_sandbox`, just different endpoints/tokens), `LocalSdcFiscalizer`, or `MockFiscalizer`. `Terminal::effectiveFiscalMode()`/`effectiveFiscalToken()` resolve a per-terminal override (`terminals.fiscal_mode`, `terminals.fiscal_endpoint_override`, `terminals.fiscal_token` — the token column is `encrypted`) falling back to global config (`config/fiscal.php`: `FISCAL_MODE`, defaults to `mock`). Everything downstream (`CheckoutService`, the outbox worker) depends only on `FiscalizerContract` — adding a new adapter means adding one `match` arm and one class, nothing else changes. `AbstractHttpFiscalizer` is the shared HTTP transport both real adapters extend; it classifies FBR's response into retryable vs. permanent failure (4xx other than 429 = payload rejected, will never succeed by retrying; 5xx/429/timeout = retryable).

`FbrInvoicePayloadBuilder` builds the exact PostData JSON contract and — as defense in depth on top of what `CheckoutService`/`ReturnService` already validated at creation time — re-asserts header totals reconcile against summed line items on *every* submission attempt (including retries of old invoices), throwing `InvoiceTotalsMismatchException` (non-retryable) rather than ever sending FBR an out-of-balance invoice.

### Two independent offline layers — don't conflate them

1. **Backend ↔ FBR** (`fiscal_outbox` pattern above): the till can always reach the Laravel backend, but the backend can't reach FBR. Handled entirely server-side; invisible to the cashier beyond a "pending" badge.
2. **Frontend ↔ backend** (`frontend/src/lib/saleSubmission.ts` + `frontend/src/db/offlineDb.ts`): the till itself is offline (no network at all). `submitSale()` tries the real API call; if it fails with no `error.response` (i.e., the request never reached a server, vs. a real validation error which must still surface to the cashier), the whole `SaleRequest` is queued in IndexedDB (`pendingSales` store) and `flushPendingSales()` drains it in order on reconnect. A queued sale still prints a receipt locally with an estimated total — the backend is what actually allocates the USIN and fiscalizes, once the queued request finally lands.

A product catalog mirror also lives in IndexedDB (`products` store, indexed by barcode and item code) so barcode scanning/search keep working through a connectivity drop.

### Customers

`Customer` records carry `customer_type` (`walk_in`/`b2b`) and NTN (7 digits)/CNIC (13 digits, both digit-normalized via mutators). `further_tax` remains a real column on `invoices`/`invoice_items` and a real key (`FurtherTax`) in the FBR PostData payload — it's part of the FBR invoice contract — but nothing in this app currently computes a non-zero value for it; it's always `0` unless populated explicitly (e.g. replaying a past sale's line items).

Buyer identity (name + NTN/CNIC) becomes mandatory once `total_bill_amount` exceeds `Invoice::BUYER_CAPTURE_THRESHOLD` (100,000) — enforced in `CheckoutService::assertBuyerCaptured()`.

### Permissions

A single source of truth, `App\Support\PosPermissions`, lists every gated action as a constant (spatie/laravel-permission under the hood). Three roles: `cashier` (no special permissions — anti-fraud default), `manager` (most permissions), `admin` (all, via `PosPermissions::all()`). Service classes check permissions inline (`$actor->can(...)`, throwing `AuthorizationException`) rather than via route middleware, since the gating is often conditional on computed values (e.g. discount %) rather than the route itself.

### Money/decimal handling

Two parallel conventions coexist and both matter: `bcmath` string arithmetic (`CheckoutService`, `SaleTotalsCalculator`) and `brick/math` `BigDecimal` (`ReturnService`, wrapped by `App\Services\Fiscal\Support\Money`). `ReturnService` in particular recomputes partial-return amounts from the *original line's per-unit* price/tax/discount rather than as a ratio of already-rounded line totals, specifically so repeated partial returns of the same line don't compound rounding drift.

### Receipts vs. printing split

`ReceiptDataBuilder` (backend) assembles structured JSON — business info, line items, totals, and a `fiscal` block (`is_pending`, `qr_payload`, `fbr_invoice_number`) — served at `GET /api/sales/{invoice}/receipt`. The print-agent (separate service, separate repo folder) is the only thing that renders the FBR QR code/logo and talks ESC/POS; it is stateless and keeps no print history (that lives in `invoices.fiscal_status`/`synced_at` on the backend). A pending (not-yet-synced) invoice prints with an "FBR SYNC PENDING" banner and no QR/logo; reprinting after sync gets the compliant version.

### API response shape gotcha

`AppServiceProvider::boot()` calls `JsonResource::withoutWrapping()` globally, so every `JsonResource`-based endpoint matches the plain-array convention used by controllers that don't use resources at all. The consequence that has caused real bugs twice: a **paginated** resource collection still gets Laravel's `data`/`links`/`meta` wrapper, but a **non-paginated** resource collection (e.g. `CustomerController::index()`'s `->get()`) is a flat array with no wrapper. Check whether an endpoint paginates before assuming its response shape.

### Audit trail

`AuditLog::record()` is called at the key mutation points (invoice creation, credit/return creation) capturing before/after state — check `app/Models/AuditLog.php` and its call sites before adding new state-changing operations, to keep the trail consistent.
