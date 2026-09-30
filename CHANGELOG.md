# Changelog

All notable changes to `pandabear/mlm`. The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and the project uses [Semantic Versioning](https://semver.org/).

## [Unreleased]

## [1.0.0] - 2026-10-01

The first stable release. It establishes the initial stable public API — the surface listed in `docs/public-api.md` — under Semantic Versioning: a breaking change there needs a new major version. Classes not listed there, and every class marked `@internal`, are implementation details. The release evidence is in `docs/production-readiness.md`. Every entry below is new since 0.1.0.

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

### Security and hardening

- Drivers, strategies and metrics resolve only through their registries, by key; no stored or configured value names a class, callable or SQL.
- The rule language is data: groups and conditions over registered metrics, with a fixed set of operators and decimal operands.
- History and financial models refuse Eloquent writes; money moves only through balanced, idempotent ledger transactions.
- The package ships no credentials and does not log; a payout's destination reference is opaque.

### Compatibility

- PHP `^8.2`, Laravel 12 and 13, Panda Panel `^0.5.7` — the full suite ran on PHP 8.2.33 and 8.4.22, Laravel 12 and 13, and Panda Panel 0.5.7 and 0.5.8, and the distributed archive installed into clean Laravel 12 and 13 applications.
- Full release gate verified on SQLite 3.53.4, MySQL 9.6.0 and PostgreSQL 18.4. MySQL 8 and PostgreSQL 15 are intended compatibility floors but did not run in the release gate.

### Known limitations

No payment provider, tax, fees or KYC; no automatic scheduling; no automatic matrix spillover and one matrix network per program; direct sponsor and unilevel calculations look sponsor lines up entry by entry, and a calculation's memory grows linearly with its entries; no continuous integration. The full list is in the README.

### Upgrading from v0.1.0

Run `php artisan migrate`. Migrations 000009 to 000040 add columns and tables only; v0.1.0's data is kept as it is, and nothing is backfilled into the new tables. Production downgrades are not supported once financial data exists: restore a backup or fix forward.

## 0.1.0 — 2026-09-28

- Programs and members with program-scoped external identity; plans and plan versions with their lifecycle; sponsor and placement genealogies with closure tables; an exact, immutable volume history with idempotent recording, reversal and totals; the metric registry and engine with `member.volume`.

[Unreleased]: https://github.com/chocoalano/mlm-engine/compare/v1.0.0...HEAD
[1.0.0]: https://github.com/chocoalano/mlm-engine/compare/v0.1.0...v1.0.0
