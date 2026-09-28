# ADR-012: Genealogy paths record when they took effect

## Status

Accepted — Phase 2.2.

## Context

The closure table (ADR-007) answers "who is related now". Network metrics will need "who was above or below this member at a given moment": a sale in February must count for the upline the member had in February, not for one joined in March. Today's tree cannot be assumed to have existed in the past. The direct edges carry their moment (`assigned_at`, `placed_at`), but deriving ancestry at a moment from them means walking the edges — exactly what the closure table exists to avoid.

## Decision

- **The trees only grow.** A member is sponsored once and placed once; nothing is reassigned, moved or removed. Once `A → D` holds, it holds forever. So one moment per path is enough: the tree at `T` is the paths with `effective_from <= T`.
- **`effective_from` on every path.** `mlm_genealogy_paths` gains a non-null `effective_from` (`dateTime`, whole seconds, like `assigned_at`, `placed_at` and `effective_at`). For a path `A → D` it is the first moment `D` was below `A` in that tree: the moment the last edge needed to complete the chain was written. The path's identity, `(tree_type, ancestor_id, descendant_id)`, is unchanged.
- **Attachment.** When an edge joins two components, every path it creates takes the edge's moment. Before that edge the two sides were not connected, however long each had existed: if `B → C` dates from January and `A` sponsors `B` in March, `A → C` dates from March.
- **Self paths** take the moment of the member's first edge in that tree. That is structure only — no member is its own sponsor or parent — and public queries still exclude them.
- **The edge's moment is the source.** A sponsorship or placement takes one moment, `EffectiveMoment::of(now)`, after its locks are held, and uses it for the edge, any self path it creates and every path it attaches. No caller supplies or backdates it: there is no `effectiveAt` argument. Backdating and historical import need their own consistency rules and are out of scope.
- **No `effective_until`.** Nothing ends a relationship, so a path is never closed. Sponsor correction, placement moves or removal will need an interval model — `effective_from <= T < effective_until` — and a redesign of this decision; they are not implemented.
- **Historical queries.** `directSponsorAt`, `directMembersAt`, `ancestorsAt`, `descendantsAt` on `SponsorGenealogy`, and `directParentAt`, `directChildrenAt`, `ancestorsAt`, `descendantsAt` on `PlacementGenealogy`. A relationship exists at `$at` when its moment is `<= $at`: the second it takes effect is included. `$at` is normalised as volume moments are (ADR-009): the same instant in the application's timezone, to the second, through the internal `Support\EffectiveMoment`, which `VolumeInput::moment()` now shares, so genealogy does not depend on volume. Direct queries compare the edge's `assigned_at` or `placed_at`; ancestry reads the closure table filtered by `effective_from` and `tree_type` — never a walk of the edges. `maxDepth` and the result types are unchanged. The queries without a moment keep answering the tree as it stands, whatever the clock says. Reads never write.
- **Two independent histories.** The sponsor and placement trees keep their own moments: a member sponsored in January and placed in March was sponsored, not placed, in February.
- **Index.** `(tree_type, ancestor_id, effective_from, depth)` serves "descendants of X as of T" as a range inside one ancestor. Ancestors need no counterpart: a member has only as many ancestors as the tree is deep, and the existing `(tree_type, descendant_id, depth)` index already finds them.

## Upgrading v0.1.0 data

Migration 000009 must not stamp existing paths with the time it runs: that would claim every relationship began at the upgrade. It rebuilds each path's history from the edges instead:

1. Adds `effective_from` as nullable.
2. Replays each tree's edges into a scratch table, `mlm_genealogy_paths_replay_000009`, oldest first — ordered by the edge's moment, then its id — the way the genealogy writes them: every path an edge creates takes that edge's moment, and a self path its member's first edge. The result is, for every path, the latest edge moment along its chain. Edges in the same second may replay in either order without changing any moment; the id only makes the order repeatable. Nothing assumes ids are in time order. Edges are read in chunks by keyset, and every write is set-based, so memory stays bounded.
3. Compares the replayed structure with the stored paths — every pair, at the same depth, and nothing more — per tree, and refuses paths of any other tree type. If they differ, or the edges close a cycle, the paths were written outside the genealogies and have no history to recover: the migration stops with an exception saying the existing genealogy edges and closure paths are inconsistent, and writes no moment.
4. Copies each replayed moment onto its path, makes the column non-null, and adds the index.

The scratch table is dropped whether the migration succeeds or fails. On PostgreSQL and SQLite a failed run rolls back entirely. MySQL cannot roll back schema changes, so a failed run leaves the nullable column in place, empty; once the paths are corrected, the migration runs again from there. `down()` drops the index and the column and keeps the paths.

## Consequences

- Network metrics can ask for a member's upline or downline at the moment of the activity they count — conceptually `path.effective_from <= activity time`. How reversals are attributed is a network-metrics decision, not this one. No network metric exists yet.
- The history is exactly as reliable as the edges' moments. Genealogy writes within a program run one at a time, after the program lock (ADR-006), and take their moment after it, so with a clock that does not run backwards a new edge is never older than the edges it joins, and "the attaching edge's moment" equals "the latest edge on the chain". The upgrade reconstructs the latter.
- Every attach now writes one more column and maintains one more index. On a local benchmark — 50,000 members in a five-way tree, 375,588 paths — "descendants of the root as of an early moment" (1,000 of 50,000) went from 16.8 ms to 1.4 ms on PostgreSQL 18.4 and from 64 ms to 3.7 ms on MySQL 9.6 with the index. At a near-current moment (45,000 of 50,000) it went from 59 ms to 39 ms on PostgreSQL and was unchanged on MySQL, which keeps the primary key; the query without a moment was unchanged on both. One machine, one tree shape: indicative, not a benchmark of the package.
- The existing MySQL limitation stands: inside a caller's transaction whose REPEATABLE READ snapshot predates a concurrent write, the already-sponsored and cycle checks may read that older snapshot, and a race surfaces as a unique-constraint violation rather than `InvalidSponsorAssignment` or `InvalidPlacementAssignment`. Integrity is still held by the keys. Moments do not change this.
- A future move or correction must rewrite paths *and* their moments, and decide what history means for the paths it ends.
