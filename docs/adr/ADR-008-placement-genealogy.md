# ADR-008: Placement genealogy records where a member sits, separately from sponsorship

## Status

Accepted — Phase 1.3.

## Context

Network plans place members in a structure — typically under someone other than their sponsor. Where a member sits and who introduced it are different facts with different lifecycles (ADR-006). Binary and matrix plans will later add their own rules on top: capacity, positions, spillover. The generic layer has to record the structural parent without committing to any of them.

## Decision

- **Placement is its own graph.** `PlacementGenealogy` answers "where is this member placed". It never reads or writes sponsor edges or sponsor paths, and sponsorship never reads or writes placement. A member may be sponsored by one member and placed under another, sponsored without being placed, placed without a sponsor, or neither. No placement column is added to `mlm_members`.
- **One placement parent, or none.** `mlm_placement_edges` holds one row per placed member (`UNIQUE (member_id)`), naming its `parent_id` and `placed_at`. A member without one is a placement root; a program may have any number of them and needs no designated root.
- **Unlimited children in the generic layer.** A parent may have any number of members placed under it. Capacity belongs to later plan-specific strategies.
- **Same program, acyclic.** Member and parent must be in the same program. A member cannot be placed under itself or under anyone in its own placement subtree. Cycle checks read placement paths only, so the two trees may even run in opposite directions.
- **Placed once.** `place()` is the only write. There is no move, reassignment or removal: moving a member rewrites structure that calculations will depend on, and needs explicit historical and recalculation semantics first.
- **The closure table is reused.** Placement paths live in `mlm_genealogy_paths` under `tree_type = 'placement'`, with the same semantics, self paths and set-based attachment as sponsor paths (ADR-007). The schema did not change; the existing keys and indexes already lead with `tree_type`.
- **No position, slot, side or leg.** The generic layer records only which member is the structural parent. A generic position column would carry meanings — left/right, slot 1..n — that the core cannot validate. Position storage waits for the first strategy that defines it. `directChildren()` is ordered by `placed_at`, which is chronology, not a slot.
- **No plan dependency, no calculation data.** Placement belongs to program and member. Plan-specific policies will later decide *how* a placement is chosen or validated; the stored relationship stays plain graph data.
- **Same write discipline as sponsorship.** `PlacementEdge` is read-only through Eloquent. `place()` locks both members in key order with a shared lock, then the program row exclusively (ADR-006 explains why the member lock is shared), re-reads everything inside one transaction, and writes the edge and its paths together.

## Consequences

- Sponsorship and placement can diverge freely, and each is queried on its own terms.
- Sponsor and placement writes both lock the program row, so within one program they serialise against each other as well as among themselves. That is correctness first; a finer lock would need benchmarks to justify it. The serialisation relies on MySQL and PostgreSQL row locks and is exercised with real concurrent sessions by the opt-in real-database suite; SQLite, which the default suite runs on, does not exercise `FOR UPDATE`.
- A member that takes part in the placement tree — placed, parent, or root with only its self path — cannot be deleted; its edges and paths restrict it.
- Binary, matrix and automatic placement are strategies still to come. They will build on this graph rather than change it.
