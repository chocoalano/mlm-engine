# Production readiness

The evidence behind a Panda MLM release: what is supported, what was verified and how, the invariants the suite holds, and what is still open. Prepared in Phase 4.0A (package hardening); Phase 4.0B completes the compatibility matrix and the release-candidate gate.

## Supported platforms

| | Declared | Verified | How |
| --- | --- | --- | --- |
| PHP | `^8.2` | 8.4.22 | full suite. **8.2 and 8.3 have not run** — no runtime was available. A static review found no syntax or function newer than 8.2, and `ArchitectureTest` keeps it that way. |
| Laravel | 12, 13 (`illuminate/*` `^12.0\|^13.0`) | 13.33.0 | full suite (Testbench 11.3). Laravel 12 is claimed by the constraint; re-running it is a Phase 4.0B cell. |
| Panda Panel | `chocoalano/panel` `^0.5.7` | 0.5.8 and 0.5.7 | full suite on 0.5.8; the Panel and plugin suites on 0.5.7 (a scratch install pinned to it). 0.5.8 adds only a `MoneyInput` field, which the plugin does not use. `PandaPanelContractTest` names every API the plugin calls. |
| SQLite | bundled | 3.53.4 | full suite (every test run). |
| MySQL | — | 9.6.0 | full suite (Phase 3.9B), targeted Phase 4.0A tests. **MySQL 8 has not run.** |
| PostgreSQL | — | 18.4 | full suite (Phase 3.9B), targeted Phase 4.0A tests. |

The supported minimum database versions are a Phase 4.0B decision, based on Laravel's own support and on what is actually run — not on the versions installed locally.

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

Not indexed, deliberately left for measured need: payout requests and commissions by `status` alone. The overview's counts and the status filters read them by status; at the volumes expected for v1 this is a short scan, and an index should follow a measured query, not precede it.

## Performance sanity

Opt-in: `MLM_PERFORMANCE_SMOKE=1 composer test -- --filter=PerformanceSmoke`. On SQLite (Phase 4.0A, PHP 8.4, laptop):

| Step | Time | Queries |
| --- | --- | --- |
| 2,000 members in a ten-wide sponsor tree | 14.9 s | — |
| 5,000 volume entries | 10.6 s | — |
| 1,000 funded wallets (2,000 postings) | 7.5 s | — |
| `sponsor.network.volume` of the root | 0.01 s | 2 |
| balances of 1,000 wallets | 0.07 s | 4 |
| genealogy explorer, 5 levels, capped at 500 | 0.03 s | 8 |
| direct sponsor calculation of 5,000 entries | 6.3 s | 5,065 |

**Known:** the direct sponsor and unilevel strategies look each eligible entry's sponsor line up on its own — one to two queries per entry. Linear, not set-based as the matrix strategies are (`MatrixAncestry`). Correct, and acceptable at this size; a set-based sponsor ancestry reader is the fix if calculation time becomes a constraint.

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

## Clean install procedure (Phase 4.0B)

`scripts/clean-install-smoke.sh` (development only, not in the archive) installs the working tree into a fresh Laravel application in a temporary directory and checks: the Composer install, provider discovery, configuration, migrations, the plugin on a panel, and the main services resolving. Run it once per Laravel version:

```bash
scripts/clean-install-smoke.sh 13
scripts/clean-install-smoke.sh 12
```

## Phase 4.0B matrix

| Cell | Status |
| --- | --- |
| PHP 8.2, Laravel 12, SQLite | **to run** — needs a PHP 8.2 runtime |
| PHP 8.4, Laravel 12, SQLite | to run |
| PHP 8.4, Laravel 13, SQLite / MySQL 9.6 / PostgreSQL 18 | passing (Phase 3.9B full, 4.0A full SQLite) |
| MySQL 8 | decide: run, or document 9.x as the tested line |
| Panda Panel 0.5.7 lowest | passing (Panel and plugin suites) |
| Clean install into Laravel 12 and 13 | to run |

## Known limitations

Deliberately out of scope for v1, not defects:

- no payment provider, bank or webhook integration — payouts record an external settlement;
- no tax, fees, KYC or AML;
- no automatic scheduling — periods, calculations and payouts run when an operator or the application asks;
- no automatic placement or matrix spillover, one matrix network per program, no moving or removing a network position;
- no manual or positive commission adjustments, no persisted ranks;
- the Plan Builder edits one group of rule conditions; nested groups are removed and re-added;
- the genealogy explorer is a table-drawn tree — Panda Panel cannot load Vue components from a plugin;
- no continuous integration in the repository: the suite runs locally.

## Open blockers for v1.0

1. **PHP 8.2 runtime unverified.** The package declares `^8.2`; it has only run on 8.4. Run the suite on 8.2 (Phase 4.0B), or raise the constraint deliberately.
2. **Laravel 12 not re-run in Phase 4.0.** Run the suite and the clean install on Laravel 12.
3. **Clean install not yet executed.** The procedure exists; Phase 4.0B runs it.
4. **Supported database minimums undecided.** Decide MySQL 8 and the PostgreSQL floor, and verify what is claimed.
