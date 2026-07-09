# FBR POS Integration — Sandbox Testing Phase Report

**Date:** 2026-07-09
**Test terminal:** POS ID 820590, terminal reference 61AB0185, terminal_id=8, operator Hassan Iqbal
**Endpoint:** `https://ims.pral.com.pk/ims/sandbox/api/Live/PostData` (sandbox only — never touched a production endpoint or token)

## Go / No-Go Recommendation

**Conditional GO for sandbox cutover, NO-GO for production cutover yet.**

Every scenario that could be tested without a live PRAL credential passed (after fixing three real bugs found along the way — see below). The application's offline-resilience, concurrency, guard-rail, and compliance-monitoring logic is sound and now has regression coverage. However, **the entire FBR network-scenario suite (A1–A7) is unverified** because no working `FBR_SANDBOX_TOKEN` was ever obtained — the POS ID visible in the FBR IRIS "Pos Details" screen showed no token, and no PRAL integration documentation was available to determine the correct token-retrieval flow. Nothing that depends on a real FBR response (payload acceptance, real `InvoiceNumber` format, real error codes, true PRAL-side idempotency behavior) has been verified. Do not cut over to production, or even consider the sandbox suite complete, until A1–A7 are run against a verified sandbox credential obtained through proper PRAL channels.

---

## Section A — FBR Network Scenarios (A1–A7): BLOCKED

**Status: Not run. Blocked on credentials, not on code.**

- `backend/.env` and `.env.testing` were checked; no `FBR_SANDBOX_TOKEN` was ever present.
- Per the task's own explicit rule ("if authentication fails, STOP and report... do not retry with modified credentials or route around auth failures"), no attempt was made to guess, fabricate, or route around a missing/invalid credential.
- The user located an FBR IRIS "Pos Details" screen but it showed no token for POS ID 820590; a separate screenshot showed what appears to be **production** credentials for a different, real business (CHANGAN MULTAN MOTORS) — this was flagged to the user immediately, the tokens were not used, logged, or acted upon in any way, and the user was warned they don't match the sandbox terminal anyway.
- The user confirmed no PRAL integration documentation is available.
- **Recommendation:** contact PRAL support directly to determine how a test/sandbox POS ID obtains its bearer token (a separate registration/handshake call may be required beyond what's visible in IRIS). Once a token is available, set `FBR_SANDBOX_TOKEN` in `backend/.env` and re-run this suite — the connectivity-probe-first rule should still be followed for that run.

Blocked/dependent items: **D15** (receipt verification against a real synced invoice) and **E17** (reconciliation report against real FBR responses) are blocked for the same reason.

---

## Section B — Resilience & Failure-Mode Tests

All of the following were run against the **real terminal_id=8** (POS 820590 / 61AB0185), using real checkout/return flows through the actual application code (CheckoutService, ReturnService, FiscalSubmissionService) and, where relevant, real HTTP calls through the actual API — not mocked business logic. Network calls to FBR itself were pointed at either an intentionally unreachable address or a local test server (never at the real PRAL sandbox, since no valid token exists), consistent with the "sandbox only, abort on non-sandbox" rule extended to mean "never send unauthenticated noise at a live government API when the outcome teaches us nothing".

### B8 — Offline queue (previously verified, carried over)
**PASS.** Terminal endpoint temporarily overridden to an unreachable address; a real checkout completed instantly for the cashier (correct UX), fiscal submission correctly queued for retry (`fiscal_status=pending`, `printed_offline_pending=true`). Override restored afterward.

### B9 — Crash-recovery sweep
**PASS.** Simulated a worker that crashes mid-HTTP-call by forcing a `fiscal_outbox` row into `processing` with a `locked_at` older than the 120-second staleness window (and, on the second attempt, also backdating `next_attempt_at` to accurately mirror what a real crash leaves behind). Running `php artisan fiscal:sweep-outbox` correctly:
- Reclaimed the stale `processing` row back to `pending` ("Reclaimed 1 stale-processing row(s)").
- Re-dispatched it immediately ("dispatched 1 overdue pending row(s)"), confirmed by a fresh job appearing in the queue.

Note: a live background queue worker was active throughout testing and raced the first attempt at this test (it reclaimed the row itself via `FiscalSubmissionService::claim()`'s own staleness check before the dedicated sweep command ran) — this is expected/correct behavior of the two independent safety nets, not a bug; the test was redone with the competing queued job removed first to isolate and verify the sweep command specifically.

### B10 — Idempotency after timeout
**PASS, with an important caveat documented below.** Built a local test HTTP server that sleeps 20 seconds on its first request (exceeding the app's 15-second `fiscal.http_timeout_seconds`) and responds successfully on every subsequent request — simulating "the response was lost after FBR had already received the request." Pointed terminal 8 at this server and let the live queue worker process a real invoice:
- Attempt 1: genuine client-side timeout after 15016ms (`cURL error 28`), correctly marked retryable.
- Attempt 2 (automatic retry): HTTP 200 in 111ms, marked synced.
- Final state: **exactly one** `fiscal_outbox` row and **exactly one** invoice row for this sale — no duplication on our side. `fbr_invoice_number` correctly reflects the second (successful) response.

**Caveat (unverifiable without real PRAL access):** this proves our own retry logic never creates duplicate records on our side. It does **not** prove FBR-side idempotency. The classic PostData payload (`FbrInvoicePayloadBuilder`) sends no idempotency/dedup key to FBR at all — if a real timeout occurs *after* FBR's server has already accepted and fiscalized the first attempt, our system will retry with an identical payload, and the classic PostData API contract, as documented to us, gives no indication it would recognize this as a duplicate. This could result in the same sale being fiscalized twice under FBR. **This must be verified directly with PRAL/FBR documentation or sandbox testing before production cutover** — it cannot be resolved from our side alone without a real "check if already submitted" mechanism, which the classic API does not appear to expose.

### B11 — Over-return guard
**PASS.** Tested via the real `/api/returns` HTTP endpoint (not just unit-level service calls) against a real 3-unit sale on terminal 8:
- Partial return of 2 units: succeeded (credit invoice created, USIN issued).
- Attempting to return 2 more units (only 1 remained): **rejected**, HTTP 422, `"Cannot return 2 of APP-001; only 1.000 remain returnable."`
- Returning exactly the remaining 1 unit: succeeded.
- Attempting to return even 0.001 more after that: **rejected**, HTTP 422, `"Cannot return 0.001 of APP-001; only 0.000 remain returnable."`

The guard correctly aggregates multiple partial returns and enforces the boundary to three decimal places of precision.

### B12 — Malformed payload guard
**FAIL → FIXED.** `FbrInvoicePayloadBuilder::assertTotalsReconcile()` already correctly refuses to build a payload when an invoice's header totals don't reconcile with the sum of its line items (defense-in-depth against ever sending FBR out-of-balance data) — verified this by directly corrupting a real invoice's `total_bill_amount` via raw SQL and confirming **no HTTP request was ever sent**.

However, the resulting `InvoiceTotalsMismatchException` was **not caught** anywhere in the submission path. It propagated out of `FiscalSubmissionService::submit()` as an unhandled exception, which meant:
- The `fiscal_outbox` row was left stuck in `processing` forever (the `record()` phase that would normally log the attempt and set a final status was never reached).
- No `FiscalOutboxAttempt` row was created — the failure was invisible to the audit trail.
- The row would only ever get reclaimed by the crash-recovery sweep (120s later), which would dispatch it again, hit the same exception again, forever — an infinite silent retry loop that never surfaces on the compliance dashboard, for a class of failure that can never succeed by retrying.

**Fix applied** (`backend/app/Services/Fiscal/FiscalSubmissionService.php`): wrapped the `$fiscalizer->submit($invoice)` call in a `try/catch` for `InvoiceTotalsMismatchException`, converting it into a synthetic non-retryable `FiscalizationResult::failure(...)` that flows through the normal `record()` path — identical treatment to a non-retryable 4xx rejection from FBR itself. Verified: the outbox now correctly reaches `failed_permanent` with exactly one logged attempt and a clear `last_error` message; the invoice's `fiscal_status` correctly becomes `failed_permanent` (visible on the compliance dashboard). Added a regression test (`test_header_item_totals_mismatch_fails_permanently_without_calling_fbr` in `tests/Feature/Fiscal/FiscalRetryBehaviorTest.php`), which also asserts `Http::assertNothingSent()`.

### B13 — Compliance alert threshold
**FAIL → FIXED.** Backdated a real pending `fiscal_outbox` row's `created_at` by 2 hours (simulating a sustained outage) and ran `php artisan fiscal:check-pending-age` with the threshold temporarily lowered (in-process `config()` override only, scoped to a single script invocation — nothing persisted, so no restore step was needed).

Found: `age_minutes=-120` — **negative**. Carbon 3.x (bundled with this Laravel version) changed `diffInMinutes()` to return a *signed* difference by default rather than always-absolute, so `now()->diffInMinutes($pastTimestamp)` now returns a negative number when the timestamp is in the past. This bug existed in two places:

1. `CheckPendingAgeAlertCommand` — the logged/emitted `age_minutes` was negative (cosmetic/confusing in logs and alerts, plus triggered a PHP deprecation warning from an implicit negative-float-to-int truncation when constructing the event).
2. **`ComplianceService::syncHealth()`** — `'is_breaching_threshold' => $ageMinutes > $thresholdMinutes` compared a **negative** age against a positive threshold, which is **never true, no matter how old the pending invoice actually is.** This is a serious finding: the admin compliance dashboard's breach indicator was silently and completely non-functional — it would never flag an outage, defeating the entire purpose of the FBR-mandated 24-hour outage-reporting requirement this dashboard exists to support.

**Fix applied** (`backend/app/Console/Commands/Fiscal/CheckPendingAgeAlertCommand.php` and `backend/app/Services/Compliance/ComplianceService.php`): pass `true` (absolute) as the second argument to `diffInMinutes()`, and cast the result to `(int)` in both places (Carbon still returns a float even in absolute mode, which was the source of the deprecation warning). Verified: age now reports correctly as a positive integer (~123 minutes for a ~2-hour-old row); `is_breaching_threshold` correctly toggles `true` at a 60-minute test threshold and correctly `false` at the real 24-hour (1440-minute) production default for the same data. Added two regression tests in new file `tests/Feature/Compliance/ComplianceSyncHealthTest.php`.

---

## Section C — Concurrency (Scenario 14, re-verification)

**PASS** (carried over from earlier in this session). `php artisan usin:concurrency-test 8 --n=30 --delay-ms=25` produced 30 gapless, unique USINs across real concurrent OS processes on terminal 8. A follow-up rollback test confirmed a rolled-back transaction correctly "burns" no USIN (no gap is left, since the counter row's lock is held only for the duration of the transaction). Counter reset to 0 afterward to avoid polluting later tests.

---

## Section D — Receipts & Print Agent

### D15 — Receipt verification for real synced invoices
**BLOCKED.** Depends on a real FBR-synced invoice, which depends on A1–A7 (see above).

### D16 — Print-agent dry run + QR decode
**PASS**, with one bug found and fixed, using a documented substitution for the missing real sandbox token.

Since no real synced invoice exists, a real invoice was checked out on terminal 8 and then had its `fiscal_status`/`fbr_invoice_number` set directly (documented substitution, clearly not a claim that FBR sync itself works) to a realistically-formatted mock FBR invoice number, purely to exercise the print-agent's receipt-rendering and QR-generation pipeline end-to-end.

- Print-agent was run in a genuine dry-run mode using `node-thermal-printer`'s file-interface behavior (any interface string that isn't `tcp://...` or `printer:...` is treated as a plain output file) — no fake network printer needed, and no real thermal hardware touched.
- The **real** JSON payload was fetched from the actual `GET /api/sales/{invoice}/receipt` endpoint (not fabricated) for both a mock-synced invoice and a genuinely-pending invoice, then POSTed to the print-agent's real `/print` endpoint.
- Synced-receipt path: printed successfully (9644 bytes of ESC/POS output including the embedded QR and FBR logo images).
- Pending-receipt path: printed successfully (871 bytes), correctly showing `*** FBR SYNC PENDING ***` / "Reprint after sync..." and correctly **not** attempting to generate a QR code or print a logo for data that doesn't exist yet.
- QR correctness was verified **without a QR decoder** (npm install was blocked — see Environment Issues below) by re-encoding the known-correct FBR invoice number with the exact same call the print-agent uses (`QRCode.toFile(..., payload, { width: 200, margin: 1 })`) and confirming the output was **byte-identical** to the file the print-agent actually generated — a deterministic, dependency-free proof the QR encodes the correct value.

**Bug found and fixed:** the temporary QR PNG file (`renderQrToTempFile` in `print-agent/src/printer.js`) was never deleted after being printed — on a busy till this would accumulate one file per receipt printed, forever, in the OS temp directory. Fixed by adding an `unlink()` call (best-effort, non-fatal) immediately after the QR image is sent to the printer. Verified via a controlled before/after temp-file count comparison with the fix in place.

---

## Section E — Reconciliation

### E17 — Reconciliation report
**BLOCKED.** Depends on real FBR-synced invoices to reconcile against; blocked on A1–A7 for the same reason as D15.

---

## Bugs Found and Fixed (summary)

| # | File | Bug | Fix | Regression test |
|---|------|-----|-----|------|
| 1 | `frontend/src/components/CustomerAttach.tsx` | Assumed `/customers` search response was wrapped in `{data: [...]}` like the paginated `/products` endpoint; it's actually a flat array (unpaginated `->get()`). Customer search silently returned nothing. | Read the response as `Customer[]` directly. | Verified live in-browser via Playwright. |
| 2 | `frontend/src/pages/CheckoutPage.tsx` | `handleConfirmNonAtl()` called `setConfirmNonAtlB2b(true)` then immediately `submit()` in the same tick; `submit()`'s closure still saw the pre-update `false`, so confirming the non-ATL prompt kept sending `confirm_non_atl_b2b: undefined` and looping the same 409. | Pass the confirmation as an explicit parameter (`submit(true)`) instead of relying on the store re-rendering first. | Verified live in-browser via Playwright (full checkout → attach → confirm → success flow). |
| 3 | `backend/app/Services/Fiscal/FiscalSubmissionService.php` | `InvoiceTotalsMismatchException` from the payload builder was uncaught, leaving the outbox row stuck in `processing` forever with no attempt logged (invisible retry-forever loop). | Catch the exception and route it through the normal `record()` path as a non-retryable failure. | `FiscalRetryBehaviorTest::test_header_item_totals_mismatch_fails_permanently_without_calling_fbr` |
| 4 | `backend/app/Console/Commands/Fiscal/CheckPendingAgeAlertCommand.php`, `backend/app/Services/Compliance/ComplianceService.php` | Carbon 3's `diffInMinutes()` returns a signed (not absolute) difference by default, producing negative ages for past timestamps. This silently broke `is_breaching_threshold` on the compliance dashboard — it could never be true, regardless of actual outage duration. | Pass `absolute: true` and cast to `(int)` at both call sites. | `ComplianceSyncHealthTest` (2 tests) |
| 5 | `print-agent/src/printer.js` | QR code temp PNG files were never deleted after printing — unbounded accumulation on a long-running till. | `unlink()` the temp file after `printImage()` (best-effort). | Verified manually via before/after temp-file count (not currently covered by an automated test, since the print-agent has no test suite). |

All fixes were verified against real data flows through the actual application code (not just reasoning about the code), and the full backend test suite (56 tests, 149 assertions) passes after all fixes.

---

## Items Unverifiable in This Environment

1. **All of A1–A7** — no working FBR sandbox token.
2. **D15, E17** — depend on A1–A7.
3. **True FBR-side idempotency on timeout** (B10 caveat above) — the classic PostData API's behavior when the same USIN/payload is submitted twice cannot be determined without real PRAL access or documentation.
4. **QR decode via a real decoder library** — `npm install` failed with `ENOSPC` because the C: drive was completely full (0 bytes free of 119GB) at the time of testing. Verified QR correctness via a deterministic byte-identical re-encode comparison instead (see D16), which is a valid substitute for *this specific check* but means no general-purpose QR-decoding tooling was exercised.
5. **Real thermal printer hardware** — D16 was a dry run against a file-interface target, not physical ESC/POS hardware or a real network printer. The generated ESC/POS byte stream was not visually verified on paper.

---

## Environment Issues to Flag (not application bugs, but worth your attention)

- **The C: drive is completely full (0 bytes free of 119GB) as of this test run.** This blocked an `npm install` during testing and could cause other failures (writes, database operations, general instability) outside the scope of this test session. Recommend freeing disk space before further development or production deployment work.
- **Two test print-agent Node processes were left running** on `localhost:9101` and `:9102` (dry-run mode, pointed at a scratch file, not real hardware) from D16 testing. I was not able to confirm-and-stop the older one through available tooling without risking an unverified process kill, so I started the fixed version on a new port instead of terminating the old one. Both are harmless (localhost-only, no real printer attached) but you may want to close them manually (they'll also close if you restart the machine, or you can stop them yourself by process name `node.exe` running `src/server.js` under `print-agent/`).
- A live `php artisan queue:work` worker and scheduler were running throughout this session (pre-existing, not started by this testing) and actively raced several of the manual test scenarios (notably B9); this is expected/correct system behavior, not a finding, but is noted here since it explains why some tests needed to be re-run with isolation (e.g., temporarily removing a competing queued job) to cleanly observe the specific mechanism under test.

---

## Test Data Hygiene

All invoices, outbox rows, attempt logs, and queued jobs created for these manual tests were deleted afterward, and terminal 8's `fiscal_endpoint_override` was restored to `null` (its pre-test state) after every test that touched it. The USIN counter for terminal 8 was reset to 0 after the concurrency test. No production or unrelated data was modified. The full backend test suite (56 tests / 149 assertions) passes as of this report.
