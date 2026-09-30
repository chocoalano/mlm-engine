# Production readiness

The production release evidence for **Panda MLM v1.0.0** (released 2026-10-01): what is supported, what was verified and how, the invariants the suite holds, and what remains open. Gathered in Phase 4.0A (package hardening) and Phase 4.0B (the compatibility matrix and the release gate), on the code released as v1.0.0; the release commit itself changed documentation only.

v1.0.0 establishes the initial stable public API baseline — the surface listed in [public-api.md](public-api.md). Under Semantic Versioning a breaking change there needs a new major version; classes not listed there are internal and carry no compatibility promise.

## Supported platforms

Three kinds of evidence, never merged: **full suite** — all 1,936 tests on that version; **compatibility-tested** — a named subset on that version; **not executed** — nothing has run there, so any support is inferred.

| | Declared | Full suite | Compatibility-tested | Not executed |
| --- | --- | --- | --- | --- |
| PHP | `^8.2` | **8.2.33** (the declared minimum) and 8.4.22 | 8.2.33 against PostgreSQL 18.4: the real-session concurrency and the production tests; clean installs on 8.2 and 8.4 | 8.3, 8.5 |
| Laravel | 12, 13 (`illuminate/*` `^12.0\|^13.0`) | 12.69.3, 12.69.0; 13.33.0, 13.30.0 | clean installs on 12.69.3 and 13.34.0 | below 12.69.0 and 13.30.0 (see below) |
| Panda Panel | `chocoalano/panel` `^0.5.7` | 0.5.7 and 0.5.8 | `PandaPanelContractTest` on both; a clean install on each | — |
| SQLite | bundled with PHP | 3.53.4, in every cell | — | — |
| MySQL | — | **9.6.0** | — | **8.x** — no MySQL 8 runtime was available |
| PostgreSQL | — | **18.4** | — | **15** — no PostgreSQL 15 runtime was available |

**Databases.** The full release gate ran on SQLite 3.53.4, MySQL 9.6.0 and PostgreSQL 18.4. No older version was compatibility-tested. MySQL 8 and PostgreSQL 15 are intended compatibility floors but were **not run** in the v1.0.0 release gate, so they are not runtime-verified: the inference is the SQL the package runs — JSON columns, `FOR UPDATE` and shared row locks, plain aggregates and column comparisons, and no common table expression, window function, `RETURNING`, upsert or `SKIP LOCKED` — which both support. Run `composer test:mysql` or `composer test:pgsql` against an older server before relying on it. SQLite is for development, tests and lightweight use; its `SUM` overflows past 64 bits, so those exact totals are checked on MySQL and PostgreSQL only (ADR-010).

**Lowest installable framework.** `--prefer-lowest` resolves Laravel 12.69.0 and 13.30.0, not 12.0 and 13.0: Composer (2.10.1 here) refuses every earlier release by default because each carries a published security advisory. The package's `^12.0|^13.0` is left as it is — Composer's advisory policy, not Panda MLM, sets that floor.

## Financial invariants

Each holds in the suite; the release scenarios (`tests/Production/ReleaseScenarioTest.php`) check all of them across the whole cycle.

- **Double entry.** Every ledger transaction has at least two postings summing to exactly zero, in one program and one currency; a reversal is its original's exact negation. `LedgerRecorder` refuses anything else before writing.
- **No stored balance.** A wallet's balance is the exact sum of its account's postings (`LedgerBalanceReader`), summed in PHP — SQLite's integer `SUM` overflows past 64 bits. `mlm_wallets` and `mlm_ledger_accounts` have no balance, available, reserved or pending column (`ModelIntegrityTest`).
- **Money moves only at posting and at payout.** Calculation, review, finalizing and releasing move nothing; `CommissionPoster` posts, `PayoutManager` reserves at approval and refunds on failure. Settlement records the provider's reference and moves nothing.
- **Payout ledger states.** Requested and cancelled: no transaction. Approved, processing, settled: exactly one reservation. Failed: the reservation and its exact reversal. `PayoutLedger` verifies this on every step.
- **The wallet is the payout boundary.** Nothing under `src/Payout` reads plan versions, periods, calculation runs, commissions or the genealogy (`ArchitectureTest`).
- **No paid commission.** `CommissionStatus` has no paid state; external settlement belongs to the payout request.
- **Exact arithmetic.** Amounts and quantities are decimal strings and integer millionths; no runtime code uses floats, `round()` or `number_format()` (`ArchitectureTest`).
- **Program boundary.** Every writer refuses facts spanning two programs itself — sponsor, placement, binary, matrix, periods, ledger postings, payouts and batches (`ProgramBoundaryTest`) — before any foreign key is asked.

## Idempotency

Every externally retryable write takes a key; the same key with the same facts returns the stored record, and the same key with other facts is refused.

| Writer | Scope and key | Other facts under the key |
| --- | --- | --- |
| `VolumeRecorder::record()` / `reverse()` | program, idempotency key | `ConflictingVolumeReplay` |
| `LedgerRecorder::post()` / `reverse()` | program, idempotency key | `ConflictingLedgerReplay` |
| `CalculationEngine::calculate()` | program, context key | `ConflictingCalculationReplay` |
| `HybridCalculationEngine` | program, batch key | `ConflictingCalculationBatch` |
| `CommissionPeriodManager::create()` | program, idempotency key | `ConflictingCommissionPeriod` |
| `CommissionPeriodCalculator` | the period (runs keyed `period:{id}:{component}`) | returns the stored result |
| `CommissionPoster::post()` / `reverse()` | the commission | returns the stored posting; a mismatch is `InvalidCommissionPosting` |
| `CommissionAdjustmentEngine` | commission, type, source | returns the stored adjustments |
| `PayoutManager::request()` | program, idempotency key | `ConflictingPayoutRequest` |
| `PayoutManager` steps | the request's status and recorded facts | `ConflictingPayoutRequest` (for example another settlement reference) |
| `PayoutBatchManager::create()` / `add()` | program, idempotency key / the request | `ConflictingPayoutBatch` / returns the item |
| `ProgramManager` | program code; member code and external identity within a program; plan code within a program | `ConflictingProgramRecord` (no replay: a code is taken) |

`ReleaseScenarioTest::test_every_step_of_the_chain_replays_without_moving_money_twice` retries each step of the chain from volume to settlement: the same records, one posting and one reservation.

## Concurrency and lock order

| Path | What serializes it |
| --- | --- |
| Sponsor, placement, binary, matrix writes | both members read-locked in key order (`ClosureTree::lockMembers`, shared), then the program row locked; a binary or matrix position then locks its slot's rows |
| Ledger idempotency | the program's unique `(program_id, idempotency_key)`; a replay re-reads under a shared lock |
| Hybrid batch | the batch row; calculation refuses to run inside a caller's transaction |
| Commission period vs volume | the period row locked by the calculator, which closes the range to input; `VolumeRecorder` reads the covering period under a shared lock before writing |
| Payout overspend | the request row, then the wallet row, then the balance read, then the reservation |
| Settle vs fail | the request row: exactly one wins |
| Batch steps | the batch row, then its requests in id order; request steps never lock a batch |

**MySQL snapshot review (Phase 4.0A).** Under repeatable read, a transaction's first plain read fixes its snapshot. The Phase 3.5–3.9 writers — periods, payouts, batches, matrix network, program manager — take their lock before any read that decides anything. The one plain read before a lock, `PayoutManager::request()` loading the member before the program lock, reads facts that never change (the member's program; the wallet's owner and program, read after it), and its idempotency check is a locking read. No change was needed. The real-session concurrency tests (`tests/Database/RealDatabaseConcurrencyTest.php`) race these paths on MySQL and PostgreSQL.

## Migrations

- **Inventory.** 40 migrations, `000001` to `000040`. v0.1.0 shipped `000001`–`000008`, unchanged since.
- **Fresh install** runs all 40 on every test (SQLite, MySQL and PostgreSQL).
- **Upgrade from v0.1.0** (`UpgradeFromV010Test`): v0.1.0's eight migrations, rows written in v0.1.0's columns, then the rest. Every row survives unchanged (genealogy paths gain their effective moment), no new table is backfilled, foreign keys hold, and the package reads and extends the old data.
- **Rollback.** Each migration's `down()` is tested, but production rollback after financial activity is not supported: a downgrade would drop history. Recover with a backup, or fix forward with a new migration.
- **Foreign keys.** Every key states its delete rule. History and financial keys are `RESTRICT`; only a draft plan version's own definition cascades — components with their version, rules with their component — and a version can be deleted only while a draft (`ArchitectureTest`).

## Indexes and query plans

Reviewed against the queries the package runs. `QueryPlanTest` asks MySQL and PostgreSQL to plan five critical reads — an anchor's genealogy descendants as of a moment, a member's volume in a range, a program's period timeline, an account's postings, a batch's items — and each is served by an index on both.

Not indexed, deliberately left for measured need: payout requests and commissions by `status` alone. The overview's counts and the status filters read them by status. Measured on MySQL 9.6 in Phase 4.0B, over the 5,000 commissions the performance smoke leaves behind: a count by status is a table scan of 1.0–1.3 ms, and the commission list filtered by status, sorted by `earned_at` and cut to a page, 5.2 ms. Not a concern at that size; an index should follow a measured query, not precede it.

## Performance sanity

Opt-in: `MLM_PERFORMANCE_SMOKE=1 composer test -- --filter=PerformanceSmoke`. On MySQL or PostgreSQL, give PHP room for the harness — the test's own query log and the data it keeps — with `php -d memory_limit=256M vendor/bin/phpunit --filter=PerformanceSmoke` and `MLM_TEST_DATABASE` set: at PHP's default 128 MB it stops with a memory error on MySQL, where SQLite's in-memory database does not count against PHP's limit.

| Step | SQLite (4.0A) | MySQL 9.6 (4.0B) | Queries |
| --- | --- | --- | --- |
| 2,000 members in a ten-wide sponsor tree | 14.9 s | 19.2 s | — |
| 5,000 volume entries | 10.6 s | 17.7 s | — |
| 1,000 funded wallets (2,000 postings) | 7.5 s | 12.9 s | — |
| `sponsor.network.volume` of the root | 0.01 s | 0.13 s | 2 |
| balances of 1,000 wallets | 0.07 s | 0.08 s | 4 |
| genealogy explorer, 5 levels, capped at 500 | 0.03 s | 0.12 s | 8 |
| direct sponsor calculation of 5,000 entries | 6.3 s | 3.7 s | 5,065 / 5,066 |

PHP 8.4 on a laptop, Xdebug off for the MySQL run.

**Known:** the direct sponsor and unilevel strategies look each eligible entry's sponsor line up on its own — one to two queries per entry. Linear, not set-based as the matrix strategies are (`MatrixAncestry`). Correct, and acceptable at this size; a set-based sponsor ancestry reader is the fix if calculation time becomes a constraint.

**Memory.** A calculation run holds its candidates while it runs: measured on MySQL, the direct sponsor calculation of 5,000 entries peaked 37 MB above the process's baseline and released all but about 1 MB when it returned — about 7.5 KB per eligible entry, linear, no leak. Size `memory_limit` for the largest period a worker calculates.

## Security boundaries

- Drivers, strategies and metrics resolve only through their registries (`PlanComponentDriverRegistry`, `CommissionStrategyRegistry`, `MetricRegistry`), by key, to objects registered in code. No database or configuration value names a class, container binding, callable or SQL (`ArchitectureTest`: no `eval`, dynamic execution, container resolution or raw SQL from data).
- The rule language is data: `RuleDefinition` reads groups and conditions with the eight operators of `RuleOperator` (`!=`, `>`, `>=`, `<`, `<=`, `in`, `not_in`, `between`) and decimal operands, and refuses anything else.
- History and financial models refuse Eloquent writes; the query-builder writes live in 24 named writer classes (`ArchitectureTest`, `ModelIntegrityTest`).
- No credentials, local paths or secrets ship; test database settings come from the environment only.
- The package does not log. A payout's destination reference is opaque and never logged.

## Panda Panel

- Every screen and action asks a capability, never a role; each operate capability runs its own area only, and every handler asks it again (`AuthorizationMatrixTest`).
- Every action opts out of the panel's transaction (`databaseTransaction(false)`): the services own theirs.
- Slugs are prefixed `mlm-`; icons are ones the framework declares, because `panel:icons` does not scan plugins.
- English and Indonesian carry the same keys, and every key the code uses exists.
- **Guests.** The plugin refuses a guest on Laravel 12 and 13 alike; the host renders the refusal. When the panel has no login of its own, Laravel 12 redirects a guest's page request to the application's `login` route — an application without one answers with an error — while Laravel 13 answers 401. Give the panel `->auth()` or the application a `login` route.
- **Caches.** Panel routes point at controllers, and `config/mlm.php` holds no closures: `config:cache` and `route:cache` both work, checked in every clean install below.

## Compatibility matrix (Phase 4.0B)

Each cell is its own install. Every run reports every PHP error level, and Laravel's deprecation log was captured with traces; a deliberate deprecation was confirmed to reach it. **No deprecation, warning or notice comes from package code in any cell.**

| Cell | PHP | Laravel | Testbench | PHPUnit | Panda Panel | Evidence |
| --- | --- | --- | --- | --- | --- | --- |
| A — minimum | 8.2.33 | 12.69.3 | 10.12.0 | 11.5.56 | 0.5.7 | full suite on SQLite; on PostgreSQL 18.4 the 58 real-session concurrency tests and the 35 production tests (with the v0.1.0 upgrade); clean install |
| A — lowest | 8.2.33 | 12.69.0 | 10.0.0 | 11.5.50 | 0.5.7 | full suite on SQLite, every dependency at its lowest (`--prefer-lowest --prefer-stable`) |
| B | 8.4.22 | 12.69.3 | 10.12.0 | 13.1.14 | 0.5.8 | full suite on SQLite; clean install |
| C — canonical | 8.4.22 | 13.33.0 | 11.3.0 | 13.3.5 | 0.5.8 | full suite on SQLite, MySQL 9.6.0 and PostgreSQL 18.4; clean install (Laravel 13.34.0) |
| C — lowest | 8.4.22 | 13.30.0 | 11.0.0 | 11.5.50 | 0.5.7 | full suite on SQLite, every dependency at its lowest |

The full suite is 1,936 tests. On SQLite 68 are skipped by design: the 58 real-session concurrency tests, 7 exact totals past 64 bits, the isolation-level test, the query plans and the opt-in performance smoke. The one deprecation source seen is vendor code: in *C — lowest*, the lowest Symfony Translation 7.x and Faker trip PHP 8.4's implicitly-nullable-parameter deprecation 17 times; the current releases of both do not.

The release gate, on cell C with the code released as v1.0.0, each engine once and one after the other:

| Engine | Tests | Assertions | Skipped | Duration |
| --- | --- | --- | --- | --- |
| SQLite 3.53.4 | 1,936 | 37,944 | 68 | 1 min 45 s |
| MySQL 9.6.0 | 1,936 | 38,519 | 2 | 3 h 42 min |
| PostgreSQL 18.4 | 1,936 | 38,445 | 2 | 11 min 31 s |

On MySQL and PostgreSQL the two skips are the opt-in performance smoke and a test that only SQLite runs. The MySQL run shared its server with another project's parallel test suite, which accounts for its length (Phase 3.9B's full MySQL run took 56 minutes).

One compatibility defect was found, in the test harness: a guest's panel request asserted Laravel 13's 401, which Laravel 12 does not give (see *Guests* above). The test now asks for JSON, answered 401 by both. No runtime code changed.

## Clean install (Phase 4.0B)

`scripts/clean-install-smoke.sh` (development only, not in the archive) exports the working tree with `git archive` — the files a Packagist user receives, `.gitattributes` applied — installs that archive into a fresh `laravel/laravel` application through a Composer package repository, and runs `scripts/clean-install-smoke.php` inside it:

- the provider discovered; the `mlm` configuration loaded; all 40 migrations shipped, found by the migrator and run; every package table created;
- `PandaMlmPlugin::make()` on the application's own panel — a `PanelProvider` listed in `config/panda-panel.php` — with a route for each of the plugin's twelve screens;
- the English and Indonesian translations; every public service resolving; no development file installed;
- a whole cycle on the backend with no panel and nobody signed in: program, members, sponsor and placement, an active plan, a sale, a period calculated, finalized and released, the commission posted, a payout requested, approved, processed and settled, the wallet back to zero;
- the same checks under `config:cache`, under `route:cache` and after `composer dump-autoload --optimize`;
- `mlm.database.connection` set to a second connection, under `config:cache`: every package table and row on it, none on the default connection.

```bash
scripts/clean-install-smoke.sh 13
scripts/clean-install-smoke.sh 12 --php /opt/homebrew/opt/php@8.2/bin/php --panel 0.5.7
```

| Application | PHP | Panda Panel | Result |
| --- | --- | --- | --- |
| Laravel 12.69.3 | 8.2.33 | 0.5.7 | passed, 66 checks |
| Laravel 12.69.3 | 8.4.22 | 0.5.8 | passed, 66 checks |
| Laravel 13.34.0 | 8.4.22 | 0.5.8 | passed, 66 checks |

The archive holds 417 files — `composer.json`, `LICENSE`, `README.md`, `CHANGELOG.md`, `config/`, `database/` (40 migrations, 4 factories), `resources/lang/`, `src/` and `docs/` — and no tests, scripts, tooling configuration, lock file, IDE or OS files. `git archive` and `composer archive` produce the same file list.

## Known limitations

Deliberately out of scope for v1, not defects:

- no payment provider, bank or webhook integration — payouts record an external settlement;
- no tax, fees, KYC or AML;
- no automatic scheduling — periods, calculations and payouts run when an operator or the application asks;
- no automatic placement or matrix spillover, one matrix network per program, no moving or removing a network position;
- no manual or positive commission adjustments, no persisted ranks;
- the Plan Builder edits one group of rule conditions; nested groups are removed and re-added;
- the genealogy explorer is a table-drawn tree — Panda Panel cannot load Vue components from a plugin;
- the direct sponsor and unilevel strategies read each entry's sponsor line on its own — linear, not set-based — and a calculation run holds its candidates in memory, about 7.5 KB per eligible entry (see *Performance sanity*);
- payout requests and commissions have no index on `status` alone (see *Indexes and query plans*);
- MySQL 8, PostgreSQL 15, PHP 8.3 and PHP 8.5 have not run the suite (see *Supported platforms*);
- no continuous integration in the repository: the suite runs locally.

## Release blockers

None open at v1.0.0. The v1.0.0 release commit changed documentation only; before it was tagged, the SQLite suite, Pint, `composer validate --strict` and `composer audit` ran once more and the archive was checked again.

| Phase 4.0A blocker | Resolution in Phase 4.0B |
| --- | --- |
| PHP 8.2 runtime unverified | The full suite, a clean install, and the concurrency and production tests on PostgreSQL, all on PHP 8.2.33. |
| Laravel 12 not re-run | The full suite on 12.69.3 (PHP 8.2 and 8.4) and on 12.69.0; clean installs. |
| Clean install not executed | Laravel 12 and 13 applications installed from the archive (*Clean install*). |
| Database minimums undecided | Full-suite verified on MySQL 9.6.0 and PostgreSQL 18.4; MySQL 8 and PostgreSQL 15 documented as not runtime-verified. |

v1.0.0 does not promise MySQL 8 or PostgreSQL 15. Running the suite on them is the step before a later release could.
