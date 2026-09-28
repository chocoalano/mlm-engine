# ADR-001: Internal identifiers are ULIDs

## Status

Accepted — Phase 1.0.

## Context

Every package table needs a primary key. Programs and members will be referenced by later domains, processed in queued and possibly distributed jobs, and moved between systems. Auto-increment keys are sequential (they leak volume and ordering), collide across databases, and are only known after an insert.

## Decision

Package models use ULIDs as primary keys, through Eloquent's own `HasUlids` trait on the shared `MlmModel` base. No custom generator.

- Columns are `$table->ulid('id')->primary()` — `CHAR(26)` — and foreign keys use `foreignUlid()`.
- Eloquent stores them lowercase (`HasUlids::newUniqueId()`), 26 characters, time-ordered.
- Business identifiers (`code`, `member_code`) are separate columns. The ULID is internal and never doubles as a business code.

## Consequences

- Keys are known before insert, unique across databases, and sort by creation time.
- Keys are strings: `getKeyType()` is `string`, `getIncrementing()` is `false`, and every foreign key to a package table must be a `foreignUlid`.
- `CHAR(26)` is larger than a binary ULID or an integer. Portability across SQLite, MySQL and PostgreSQL was chosen over storage size.
