# ADR-007: Genealogy paths are kept in a closure table

## Status

Accepted — Phase 1.2. Amended in Phase 1.3 for the placement tree, in Phase 2.2 for `effective_from` (ADR-012), in Phase 3.2: the same storage also holds the binary overlay's paths, under `tree_type` `binary` (ADR-022), and in Phase 3.5: it also holds the matrix overlay's paths, under `tree_type` `matrix` (ADR-027).

## Context

"All ancestors of X" and "all descendants of X, to depth N" are the core genealogy reads. From direct edges alone they need a recursive walk — recursion in PHP, or recursive SQL whose syntax and limits differ between SQLite, MySQL and PostgreSQL. Trees grow deep, and these reads happen far more often than writes.

## Decision

- **Two stores.** Each genealogy keeps its direct edges in its own table — `mlm_sponsor_edges`, and `mlm_placement_edges` (ADR-008). `mlm_genealogy_paths` holds every ancestor/descendant pair they imply, with its `depth` — the graph distance, never called "level".
- **Self paths.** A member's path to itself (depth 0) is an internal row, written the first time the member takes part in that tree. It makes the attachment below a single join. Public queries exclude it, reads never write it, and it never means "a member is its own sponsor" or parent. A self path in one tree implies nothing in the other.
- **Attachment is one set-based insert.** When `S` sponsors `M`, every ancestor of `S` (including `S`) is joined with every descendant of `M` (including `M`), at depth `ancestor→S + 1 + M→descendant`: `INSERT … SELECT … CROSS JOIN` on the paths table. `M` may already have a subtree; it is attached whole, with no traversal in PHP.
- **`tree_type`** is `sponsor` or `placement`, fixed by the genealogy that owns the tree and never taken from input. Both trees share the table and its indexes, which all lead with `tree_type`; every read, check and write filters on its own tree, so the same pair can have a path in each without either seeing the other's.
- **One implementation of the mechanics.** Both genealogies go through an internal `ClosureTree` bound to their tree type: the key-ordered member lock, the program lock, self paths, the cross-product attach and the relatives read exist once. It is `@internal`, not a public abstraction — each genealogy keeps its own edges, rules, exceptions and vocabulary.
- **Keys and indexes.** The primary key is `(tree_type, ancestor_id, descendant_id)`: one path per pair, and its prefix finds descendants. A second index on `(tree_type, descendant_id, depth)` finds ancestors nearest first. Both member columns reference `mlm_members` with restricted deletion.
- **Moments.** Since Phase 2.2 every path also carries `effective_from`, the moment it took effect: the attaching edge's moment, or for a self path the member's first edge. The attach writes it in the same set-based insert. ADR-012 records the temporal model and a third index, `(tree_type, ancestor_id, effective_from, depth)`.
- **The service owns the graph; the database backs local invariants.** Cycle prevention and keeping edges and paths consistent are the genealogy service's job. The database independently enforces only local rules: `UNIQUE (member_id)` refuses a second direct edge for a member, the foreign keys keep every id pointing at a member, and the primary key refuses a duplicate path. The primary key also adds a failure mode when a *correctly maintained* closure write would close a cycle — the member at the top would gain a duplicate path to itself. That is not protection against raw writes: a raw edge insert that skips the paths is accepted even when it closes a cycle, and leaves edges and paths disagreeing.

## Consequences

- Ancestors and descendants are one indexed query each, plus one query to load the members.
- Storage grows with the sum of every member's depth, not with the member count. That is the standard closure-table trade, accepted for cheap reads. No benchmark has been run.
- Paths are only correct because each tree's own service writes them. A raw write to an edge table or the paths table can make them disagree.
- A future move or correction must rewrite a whole subtree's paths in one transaction. The same cross-product makes that tractable.
