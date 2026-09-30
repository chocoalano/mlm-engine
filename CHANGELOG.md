# Changelog

All notable changes to `pandabear/mlm`. The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and the project uses [Semantic Versioning](https://semver.org/).

## Unreleased

The road from the v0.1.0 foundation to the first production release. Every entry below is new since v0.1.0; see `docs/production-readiness.md` for what is verified and what still blocks 1.0.

### Added

- **Temporal genealogy** — sponsor and placement trees readable as of any past moment (`…At()` readers); migration 000009 gives v0.1.0 paths their effective moment.
- **Planning** — versioned definitions of components and rules, a safe rule language over registered metrics, validation before a version is validated, and cloning into a new draft.
- **Metrics, qualification and ranks** — historical sponsor-network, placement-network, binary-leg and matrix-network volume metrics; a qualification engine; rank ladders.
- **Financial ledger and wallets** — system accounts and member wallets, balanced single-currency transactions, idempotent posting and reversal, exact derived balances.
- **Commissions** — audited calculation runs over registered strategies; direct sponsor, unilevel, binary pairing and matrix strategies, fixed or proportional with explicit rounding; hybrid calculation of a version's components together; a review lifecycle and posting through the ledger.
- **Binary** — an explicit left/right overlay on placement, pairing with source-by-source carry, reversal impact analysis, consumed-carry and financial correction.
- **Matrix** — one fixed-width matrix per program, explicit slots, matrix compensation.
- **Commission periods** — non-overlapping periods that close their range to input, calculate, hold approved commissions and release them for posting.
- **Adjustments** — clawback of commissions whose source volume is later reversed, before or after posting.
- **Payouts** — requests reserving wallet funds through the ledger at approval, external settlement, refund on failure, and batches that group requests without moving money.
- **Panda Panel operations** — the plugin's operational surface: programs, members and networks, the Plan Builder, the genealogy explorer, periods, commissions, wallets and the ledger, payouts and batches, behind capability-based permissions, in English and Indonesian.
- `ProgramManager` — creating programs, members and plans with taken codes refused as domain answers.
- `LICENSE` (MIT), `docs/public-api.md`, `docs/production-readiness.md`.

### Changed

- The distributed archive leaves development material out (`.gitattributes` `export-ignore`: tests, tooling configuration, the lock file, scripts).

### Upgrading from v0.1.0

Run `php artisan migrate`. Migrations 000009 to 000040 add columns and tables only; v0.1.0's data is kept as it is, and nothing is backfilled into the new tables. Production downgrades are not supported once financial data exists: restore a backup or fix forward.

## 0.1.0 — 2026-09-28

- Programs and members with program-scoped external identity; plans and plan versions with their lifecycle; sponsor and placement genealogies with closure tables; an exact, immutable volume history with idempotent recording, reversal and totals; the metric registry and engine with `member.volume`.
