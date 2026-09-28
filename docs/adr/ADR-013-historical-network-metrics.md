# ADR-013: Network volume follows the genealogy as it was when the activity happened

## Status

Accepted — Phase 2.3.

## Context

Plans reward members for the activity of the people below them. With temporal genealogy (ADR-012) the package can say who was below whom at any moment, so a network figure no longer has to pretend today's tree always existed. Two moments are involved, and they answer different questions: *which period does an entry belong to*, and *whose network does it belong to*. Reversals make the difference visible: a January sale refunded in April is April's negative figure, but only for the people who received the January sale.

## Decision

- **Two built-in metrics**, registered like `member.volume` through `MetricRegistry`, with no engine special case: `sponsor.network.volume` and `placement.network.volume`. The tree is fixed by the metric — trusted code — never passed as a parameter. Each delegates to an internal `NetworkVolumeTotals`, whose only entry points are `forSponsorNetwork()` and `forPlacementNetwork()`.
- **Descendants only.** Both count members below the anchor, depth 1 and on. The anchor's own volume is `member.volume` and never appears here, although the closure table holds its depth-0 self path; a later rule may add the two explicitly.
- **Two independent networks.** The sponsor metric reads sponsor paths only, the placement metric placement paths only.
- **Parameters.** `type` is required and validated exactly as `member.volume` and `VolumeTotals` validate it. `max_depth` is optional: a PHP integer of 1 or more — not `"2"`, `2.0`, `true` or an explicit `null`. Anything else, including `maxDepth`, `tree`, `include_self` or `levels`, is refused with `InvalidMetricParameters`. Depth, never "level".
- **Period: the entry's own moment.** The context's `[from, until)` filters `entries.effective_at`, as for `member.volume`. It is never applied to path or original-entry moments.
- **Attribution: when the activity happened.** An entry counts for the anchor only if the anchor's path to the entry's member had taken effect by the attribution moment — for an ordinary entry its own `effective_at`, for a reversal the `effective_at` of the entry it reverses. So:
  - activity recorded while a member was a root, or before it joined an upline, never reaches that upline, even after it joins;
  - a reversal reaches exactly the uplines the original reached — each clawing it back in the reversal's own period — and an ancestor who joined in between receives neither the original nor its reversal;
  - an original and its reversal in one period net to zero in the sum itself, with no special case.
- **Depth is historical too.** Paths are never moved, so a path's depth is the depth it had when it took effect, and `max_depth` applies to it.
- **One aggregate query.** `mlm_volume_entries` joined to the paths ending at each entry's member, left-joined to the reversed entry, filtered by tree, anchor, `depth > 0`, optional `depth <= max_depth`, `paths.effective_from <= COALESCE(original.effective_at, entry.effective_at)`, type and period, summing `quantity_millionths`. Nothing is loaded member by member. The sum becomes a `Quantity` from its exact millionths and a `MetricValue`; no float is involved. On MySQL and PostgreSQL a total beyond a 64-bit count of millionths stays exact; SQLite's `SUM` limit (ADR-010) stands.
- **The program bound.** The anchor is re-read from the database, so an instance changed in memory cannot move the query into another program, and entries are bounded by the stored anchor's program as a second line of defence against paths written outside the genealogies. On MySQL that bound made the optimiser read the whole program's entries through the `(program_id, idempotency_key)` key before looking at any path. The query therefore tells MySQL and MariaDB to ignore that key (`ignoreIndex`, which Laravel compiles only for MySQL). Measured below.
- **One new index,** migration 000010: `(tree_type, ancestor_id, depth)`. Without it a `max_depth` query reads the anchor's entire subtree to keep the shallow rows.
- **Nothing stored.** No network totals, metric values or caches. A projection waits for evidence.
- **Not a plan.** No qualification, thresholds, generations, legs, sides, slots, pairing or spillover. Sponsor network volume is not "unilevel", placement network volume is not "binary" or "matrix". No link to `Plan` or `PlanVersion`: metrics remain numeric facts that a future rule layer will name by key and parameters.

## Evidence

A local data set, one machine, both databases on the same laptop: a five-way sponsor tree of 50,000 members plus a 5,000-member star, 385,587 paths, 222,750 volume entries with reversals. Query time as measured by the database (`EXPLAIN ANALYZE`) unless stated. Indicative, not a benchmark of the package.

- A small network (31 members, `max_depth` 1) takes under 1 ms on both, through the path index and the volume `(member_id, type, effective_at)` index. A mid-size one (3,906 members) about 35 ms on PostgreSQL and 50 ms on MySQL; a wide one (4,999 direct members) 20–60 ms on PostgreSQL and 90–140 ms on MySQL across runs. PostgreSQL chose to scan the entries for those two; MySQL looked each member's entries up by index.
- Whole-program networks (the root of 50,000): on MySQL, with the program bound and no hint, 1.2–1.3 s all time, 1.1–1.3 s for a two-week range and 0.9–1.2 s for a month with no activity, across runs; with the hint, 0.43 s, 0.30 s and 0.12 s (best of three) — about what they take with no bound at all. Expressing the bound through the member or the anchor row instead cost MySQL two to three times as much, and one of those forms cost PostgreSQL twenty-five times. PostgreSQL planned the plain bound as well as no bound, 12–70 ms.
- `max_depth` 1 on the root: without the depth index MySQL read all 50,000 of the root's paths (about 31 ms) and PostgreSQL scanned the paths table (52–78 ms); with it MySQL read 5 rows (0.3 ms; `max_depth` 3 over a two-week range 2.5 ms instead of 37 ms). PostgreSQL uses the index for the paths but, estimating far more rows than there are, may still scan the entries (24–54 ms across runs): a planner estimate on this data, not a missing index.

## Consequences

- A network figure for any past period is stable: it depends on stored moments only — entry moments, the reversed entry's moment, path moments — never on the clock, and re-resolving it returns the same value.
- Genealogy moments are consistent with the edges they are rebuilt from, even when the application clock went back (ADR-012), so the attribution rule rests on a reliable history.
- Raw writes remain unsupported (ADR-007). The metrics do not validate the graph on every read; the program bound only limits what a raw cross-program path can reach.
- The MySQL hint names an index created by migration 000008, prefixed like every table. Renaming it outside the package's migrations would make the query fail on MySQL rather than slow down silently.
- The known MySQL write-side limitation (ADR-012) does not concern these reads.
