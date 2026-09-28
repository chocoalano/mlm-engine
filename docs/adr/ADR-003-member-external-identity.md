# ADR-003: A member's external identity is a portable string pair

## Status

Accepted — Phase 1.0.

## Context

A member usually corresponds to something the host application owns — a user, a customer, an agent — but the package cannot know what that is. Host identifiers may be integers, UUIDs, ULIDs or business codes. An Eloquent polymorphic relation would store the host's PHP class name in the package's tables, coupling the data to the host's namespace and breaking on a rename.

## Decision

- Two nullable columns, `external_type` (`VARCHAR(64)`) and `external_id` (`VARCHAR(128)`), hold a label chosen by the host and its identifier — `customer` / `CUS-00192`, `user` / `01K…`. No `belongsTo(User::class)` and no `morphTo`.
- `external_id` is stored as a string. An integer is converted on write, so `1002` and `'1002'` are the same identity on every database.
- Both halves are set together or not at all. The model refuses a half-set identity — or an empty one — on save with `InvalidExternalIdentity`, because the unique index cannot catch it: its `NULL` would never collide.
- `UNIQUE (program_id, external_type, external_id)` makes one external identity at most one member per program. The same identity may be a member of several programs.
- A member without an external identity is valid.

## Consequences

- The package's data stays independent of the host's class names and key types.
- Members without an identity rely on `NULL`s being distinct in unique indexes. That holds for SQLite, MySQL and PostgreSQL, the supported databases. SQL Server treats `NULL`s as equal in a unique index and would need a filtered index; it is not supported.
- The both-or-neither rule lives in the model's `saving` event, so it does not run for query-builder writes (`insert()`, mass `update()`) that bypass Eloquent events.
- Resolving the host record from a member is the host's job, using its own label and identifier.
