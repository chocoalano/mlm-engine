# ADR-012: Genealogy paths record when they took effect

## Status

Accepted — Phase 2.2. Amended in Phase 2.3: the write moment is floored by the parts it joins.

## Context

The closure table (ADR-007) answers "who is related now". Network metrics will need "who was above or below this member at a given moment": a sale in February must count for the upline the member had in February, not for one joined in March. Today's tree cannot be assumed to have existed in the past. The direct edges carry their moment (`assigned_at`, `placed_at`), but deriving ancestry at a moment from them means walking the edges — exactly what the closure table exists to avoid.

## Decision

- **The trees only grow.** A member is sponsored once and placed once; nothing is reassigned, moved or removed. Once `A → D` holds, it holds forever. So one moment per path is enough: the tree at `T` is the paths with `effective_from <= T`.
- **`effective_from` on every path.** `mlm_genealogy_paths` gains a non-null `effective_from` (`dateTime`, whole seconds, like `assigned_at`, `placed_at` and `effective_at`). For a path `A → D` it is the first moment `D` was below `A` in that tree: the moment the last edge needed to complete the chain was written. The path's identity, `(tree_type, ancestor_id, descendant_id)`, is unchanged.
- **Attachment.** When an edge joins two components, every path it creates takes the edge's moment. Before that edge the two sides were not connected, however long each had existed: if `B → C` dates from January and `A` sponsors `B` in March, `A → C` dates from March.
- **Self paths** take the moment of the member's first edge in that tree. That is structure only — no member is its own sponsor or parent — and public queries still exclude them.
- **The edge's moment is the source.** A sponsorship or placement takes one moment after its locks are held and uses it for the edge — including its `created_at` and `updated_at` — any self path it creates and every path it attaches. That moment is the normalised application time, `EffectiveMoment::of(now)`, floored by the latest moment already in the two parts the edge joins: the paths ending at the parent (its line and self path) and the paths starting at the child (its subtree and self path). See *Monotonic moments* below. No caller supplies or backdates it: there is no `effectiveAt` argument. Backdating and historical import need their own consistency rules and are out of scope.
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

## Monotonic moments

Live, a new path is dated by the edge that attached it. Rebuilt from the edges (the upgrade above), it is dated by the latest edge on its chain. The two agree only if an attaching edge is never older than the edges it joins. Serialising writes per program (ADR-006) is not enough for that: the application clock itself can go back — a time correction, another server. Before Phase 2.3, `B → C` written at 10:10 and then `A → B` written when the clock read 10:05 dated `A → C` at 10:05 live, while the rebuild dated it 10:10.

So the write moment is `max(now, the latest moment in the two parts it joins)`, read after the locks, from the paths ending at the parent and the paths starting at the child — two small indexed reads, never the whole program. Consequences:

- A join is never dated before anything it depends on, so every path it creates is dated by the latest edge on its chain, exactly as the rebuild dates it, and each self path by its member's first edge. Live history and rebuilt history are identical even when the clock goes back; a test rolls migration 000009 back and runs it again over history written with a wandering clock.
- An unrelated part of the program — or another program — never delays a write: there is no global clock and no clock table.
- A floor in the same second as now keeps that second; nothing is pushed a second forward, and several operations may share one second.
- Application time stays the source: the database clock is not consulted.
- This makes moments consistent with the graph they join, not correct across distributed clocks: a write on a server whose clock runs ahead still records that clock's time.

## Consequences

- Network metrics can ask for a member's upline or downline at the moment of the activity they count — conceptually `path.effective_from <= activity time`. How reversals are attributed is a network-metrics decision, not this one. No network metric exists yet.
- The history is exactly as reliable as the edges' moments. See *Monotonic moments* for how live writes and the rebuild agree.
- Every attach now writes one more column and maintains one more index. On a local benchmark — 50,000 members in a five-way tree, 375,588 paths — "descendants of the root as of an early moment" (1,000 of 50,000) went from 16.8 ms to 1.4 ms on PostgreSQL 18.4 and from 64 ms to 3.7 ms on MySQL 9.6 with the index. At a near-current moment (45,000 of 50,000) it went from 59 ms to 39 ms on PostgreSQL and was unchanged on MySQL, which keeps the primary key; the query without a moment was unchanged on both. One machine, one tree shape: indicative, not a benchmark of the package.
- The existing MySQL limitation stands: inside a caller's transaction whose REPEATABLE READ snapshot predates a concurrent write, the already-sponsored and cycle checks may read that older snapshot, and a race surfaces as a unique-constraint violation rather than `InvalidSponsorAssignment` or `InvalidPlacementAssignment`. Integrity is still held by the keys. Moments do not change this.
- A future move or correction must rewrite paths *and* their moments, and decide what history means for the paths it ends.
