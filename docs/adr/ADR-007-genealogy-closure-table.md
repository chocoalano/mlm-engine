# ADR-007: Genealogy paths are kept in a closure table

## Status

Accepted — Phase 1.2.

## Context

"All ancestors of X" and "all descendants of X, to depth N" are the core genealogy reads. From direct edges alone they need a recursive walk — recursion in PHP, or recursive SQL whose syntax and limits differ between SQLite, MySQL and PostgreSQL. Trees grow deep, and these reads happen far more often than writes.

## Decision

- **Two stores.** `mlm_sponsor_edges` holds direct sponsorships. `mlm_genealogy_paths` holds every ancestor/descendant pair they imply, with its `depth` — the graph distance, never called "level".
- **Self paths.** A member's path to itself (depth 0) is an internal row, written the first time the member takes part in the tree, as sponsor or sponsored. It makes the attachment below a single join. Public queries exclude it, reads never write it, and it never means "a member is its own sponsor".
- **Attachment is one set-based insert.** When `S` sponsors `M`, every ancestor of `S` (including `S`) is joined with every descendant of `M` (including `M`), at depth `ancestor→S + 1 + M→descendant`: `INSERT … SELECT … CROSS JOIN` on the paths table. `M` may already have a subtree; it is attached whole, with no traversal in PHP.
- **`tree_type`** is `sponsor` for every row written now, set by the service and never by input. The column lets a later structure keep independent paths in the same table without a registry today.
- **Keys and indexes.** The primary key is `(tree_type, ancestor_id, descendant_id)`: one path per pair, and its prefix finds descendants. A second index on `(tree_type, descendant_id, depth)` finds ancestors nearest first. Both member columns reference `mlm_members` with restricted deletion.
- **The database backs the invariants.** `UNIQUE (member_id)` refuses a second sponsor, and the primary key refuses a duplicate path. A cycle would give a member a second path to itself, which the primary key also refuses — so a write that bypasses the service's checks still fails at the database, just without the domain exception.

## Consequences

- Ancestors and descendants are one indexed query each, plus one query to load the members.
- Storage grows with the sum of every member's depth, not with the member count. That is the standard closure-table trade, accepted for cheap reads. No benchmark has been run.
- Paths are only correct because one service writes them. A raw write to either table can make them disagree.
- A future move or correction must rewrite a whole subtree's paths in one transaction. The same cross-product makes that tractable.
