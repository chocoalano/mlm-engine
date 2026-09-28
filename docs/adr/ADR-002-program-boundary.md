# ADR-002: Program is the top-level business boundary

## Status

Accepted — Phase 1.0.

## Context

One installation may run several independent MLM programs — a distributor network and a reseller program, for example — each with its own members. Identities that are unique in one program must be reusable in another. Later domains (planning, network, compensation) need one unambiguous owner for their data.

## Decision

- `Program` is the top-level boundary of the package. There is no organisation layer above it.
- A `Member` belongs to exactly one program: `mlm_members.program_id` is a required foreign key to `mlm_programs.id`.
- Member identities are scoped by program: `UNIQUE (program_id, member_code)` and `UNIQUE (program_id, external_type, external_id)`.
- A program's `code` is unique across the installation (`UNIQUE (code)`); its `name` is a display name and is not unique.
- Identifiers compare exactly, letter case included, on every supported database: program, member and plan codes, external identities, and volume types, sources and idempotency keys. MySQL's default collations ignore case, so there these columns use the binary `utf8mb4_bin` collation; display names keep the connection's collation.
- The program is always given explicitly — through the relationship (`$program->members()->create([...])`) or an operation's arguments. The package never reads an "active" program from the request, the session, the authenticated user, a static property or a global scope. `program_id` is not mass assignable, so a member cannot be moved into a program by an array of input.
- The foreign key restricts deletion: a program that still has members cannot be deleted. There is no cascade and no soft delete.
- A program is not a plan: it has no plan type, network type or compensation settings. Those become their own domains, related to a program.

## Consequences

- Every future domain table relates to a program, directly or through a member.
- Queries are scoped by passing the program, never implicitly — deterministic and testable, and a missing scope is visible in the code rather than hidden in a global.
- Deleting a program with members fails at the database. The deletion and lifecycle policy is decided once the domains that depend on members exist.
