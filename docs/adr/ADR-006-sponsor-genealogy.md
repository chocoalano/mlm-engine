# ADR-006: Sponsor genealogy records who sponsored whom, once

## Status

Accepted — Phase 1.2.

## Context

Every MLM program needs to know who introduced whom. Many plans also position members in a separate network structure (a binary or matrix placement) that often differs from sponsorship: a sponsor's recruit may be placed under someone else. Merging the two into one `parent_id` makes both unanswerable.

## Decision

- **Sponsorship only.** The sponsor tree answers "who sponsored whom". It is not placement. No `parent_id`, `upline_id`, position, side or leg columns exist, and none are added to `mlm_members` — not even `sponsor_id`. A placement structure, when it comes, gets its own edges and its own paths.
- **One direct sponsor, or none.** A member has at most one sponsor (`UNIQUE (member_id)` on `mlm_sponsor_edges`). A member without one is a root; a program may have any number of roots and needs no designated root.
- **Unlimited direct members.** A sponsor may sponsor any number of members. There is no width limit.
- **Same program.** A member and its sponsor must belong to the same program (ADR-002). Sponsorship never crosses programs, and queries are scoped to the member's program as well.
- **Acyclic.** A member cannot sponsor itself, and cannot be sponsored by anyone in its own sponsor subtree.
- **Assigned once.** `SponsorGenealogy::assignSponsor()` is the only write. There is no reassignment, removal or move: changing a sponsor rewrites history that calculations may depend on, so a correction will be its own audited operation.
- **Not tied to plans.** Sponsorship belongs to program and member, not to a plan or plan version, and carries no monetary or calculation data.
- **One writer.** `SponsorEdge` is read-only through Eloquent. Creating, updating or deleting an edge through the model is refused, because an edge without its genealogy paths would corrupt the tree.
- **Decided from the database.** An assignment re-reads both members under lock inside its transaction. Stale instances cannot pass a check the stored state fails.
- **Locking.** The two members are locked in primary-key order, then the program row. Key order means two assignments sharing a member cannot deadlock. The program lock is what makes concurrent cycle checks safe: two assignments over disjoint members could otherwise each pass a check that the other invalidates, as with `A→B` and `C→D` plus concurrent `B sponsors C` and `D sponsors A`. Sponsor assignments within one program therefore run one at a time.

## Consequences

- Sponsorship and placement can differ for the same member without either being distorted.
- A mistaken sponsor cannot be fixed yet. That waits for an explicit correction operation with audit semantics.
- A member that takes part in the sponsor tree — as sponsor or sponsored — cannot be deleted: its edges and paths restrict it.
- Row locks are not exercised by the SQLite test suite, where `FOR UPDATE` is a no-op and SQLite serialises writers itself. The locking is correct by design for MySQL and PostgreSQL but unproven under real concurrency.
