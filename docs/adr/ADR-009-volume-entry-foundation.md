# ADR-009: Volume is an immutable history of business measurements

## Status

Accepted — Phase 2.0. Amended in Phase 2.1: an empty or inverted range is refused, and the per-entry size limit is enforced when recording (ADR-010).

## Context

Every compensation plan consumes quantified business activity — sales, points, weighted value — attributed to members. Later phases will aggregate it across genealogies, qualify members, rank them and pay them. Those consumers need input that is exact, traceable to its source, safe to replay, and never silently rewritten.

## Decision

- **A generic measurement.** A `VolumeEntry` records that a member received a quantity of a volume type, from a business source, effective at a moment. Volume is not money: no currency, balance or wallet semantics. It is not commission either — recording volume produces nothing else.
- **No package vocabulary.** `type` is the application's own identifier — `sales`, `retail`, `team-sales`, anything — 1–64 characters of lowercase letters, digits, `.`, `-` and `_`. There is no enum of categories and no registry. `source_type` follows the same rule; neither is ever a PHP class name.
- **Exact quantities.** Quantities are exact decimals with six places, never floats (ADR-010).
- **Program and member ownership.** Each entry belongs to one member and one program, both with restricted deletion. `program_id` is denormalised from the member for an explicit boundary and program-scoped keys. The recorder takes it from the stored member, never from the caller's instance, so the two always agree.
- **Source identity.** `source_type` and `source_id` say where the activity came from (`order` / `ORD-123`). Source identity is not uniqueness: one order may yield several entries — different members, different types.
- **Idempotency.** The caller supplies `idempotency_key`, unique within the program: the program is the top-level boundary (ADR-002), so two programs may use the same key independently. A replay whose material fields all match — program, member, type, quantity, source, effective moment, and what it reverses — returns the entry already recorded. A replay that differs in any of them is refused with `ConflictingVolumeReplay`, naming the fields.
- **Concurrency.** Each operation writes one row. The unique keys on `(program_id, idempotency_key)` and on `reversal_of_id` are the backstop: a write that loses a race fails on the key and is resolved from the winning row — returned if identical, refused otherwise. The insert runs in its own nested transaction, so on PostgreSQL a duplicate-key failure inside a caller's transaction rolls back only a savepoint. The re-read after a lost race is a locking read: inside a caller's transaction on MySQL, a plain read sees the snapshot that transaction's first read took, from before the winning row was committed.
- **Effective time.** `effective_at` is when the activity counts; `created_at` is when it was stored. It is kept in the application's timezone, to the second, so one instant given in two timezones is one moment — for storage and for replay comparison alike.
- **Immutable, with explicit reversal.** Entries are never updated or deleted. `VolumeEntry` refuses creating, updating and deleting through Eloquent; `VolumeRecorder` is the only writer. A correction is a second entry with the negated quantity, the same program, member and type, its own source, key and effective moment, and `reversal_of_id` pointing at the original. An entry is reversed at most once, and a reversal cannot itself be reversed. Ordinary entries must be positive; zero and negative quantities are refused.
- **Totals from history.** `VolumeTotals::forMember()` sums a member's entries of one type — reversals included, so a reversed entry nets to zero — optionally over the half-open effective range `[from, until)`. With both bounds, `from` must come before `until`; an empty or inverted range is refused rather than answered with zero. Metrics use the same range rule (ADR-011).
- **Deliberately absent.** No running-balance table: the entries are the only source of truth until performance requires a projection. No network aggregation: genealogy totals are a later metric concern. No `plan_id` or `plan_version_id`: activity exists independently of how a plan interprets it — a future calculation run decides which plan version reads it. No genealogy snapshot on entries.

## Consequences

- Every figure a later calculation uses can be traced to entries, and every entry to its source.
- Replaying an event stream is safe, and a conflicting replay fails loudly rather than being merged.
- Totals scan entries until a projection exists; the `(member_id, type, effective_at)` index serves them. No benchmark has been run.
- Raw query-builder or SQL writes bypass every guard. The database backs the local invariants only — one entry per key and program, one reversal per entry, foreign keys — not correct signs, program/member agreement or immutability.
- The duplicate-key resolution is exercised on SQLite by simulating the competing write, and with real concurrent sessions on MySQL and PostgreSQL by the opt-in real-database suite.
