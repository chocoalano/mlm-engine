# ADR-024: A binary reversal's impact is read from the stored allocations, never recalculated

## Status

Accepted — Phase 3.4A.

## Context

When a volume entry that fed binary pairing is reversed, a pairing run stops with `BinaryPairingCorrectionRequired` if any of its carry was already paired (ADR-023). Before any correction can be designed, the package has to answer exactly what the reversal reaches: which carry lots the original created, in which components and binary members' legs, how much of each is still unpaired, and which pairings — and commissions — consumed the rest.

## Decision

- **The allocations are the history.** `BinaryReversalImpactAnalyzer::analyze()` reads the stored lots of the original entry, their allocations, the pairing results and runs those belong to, and their commissions. It does not rerun pairing, read the binary tree again, recompute pair counts or awards, or round anything: the tree, plans and rounding may have changed since, and a correction must address what was actually paid, not what would be paid today.
- **Read-only.** It writes nothing and calls no correction, lifecycle, poster, ledger or pairing service. The impact is inert data with a deterministic `toArray()`: exact canonical decimals, stored moments, no clock and no generated ids.
- **Validated input.** The reversal and its original are reloaded; the reversal must reverse its original as the volume history defines it — same program, member and type, exactly the negated quantity, an original. Anything else is refused with `InvalidBinaryCorrection`, never reported as an empty impact.
- **Every lot, apart.** All lots of the original are reported, in every component — active or not — and every binary member's leg, ordered by component, member, side and lot. An entry that never entered binary carry has no lots: a valid, empty impact.
- **Exact lot arithmetic.** A lot's consumed quantity is the sum of its allocations, added exactly in the package. An unreversed lot must hold remaining + consumed = its quantity; a lot this reversal already took back must hold nothing and have no pairings. Other states — a lot taken back by another entry, allocations that are not the lot's own result's carry — are refused as corrupt rather than turned into a misleading plan.
- **Four states.** `unconsumed` (nothing paired), `partially_consumed`, `fully_consumed`, and `already_removed` (this reversal took it back unpaired). A consumed lot — partly or wholly — makes `requiresConsumedCorrection()` true.
- **Every consumption.** Each allocation is reported with its pairing result, run, earning member, the result's consumed quantity, and its commission's id, status, amount and currency when there is one. A pairing whose proportional award rounded to zero has no commission but still consumed carry, and is reported. A lot consumed over several runs lists each, ordered by run range and id, then result and allocation id.
- **Bounded queries.** Lots, allocations, results, runs and commissions are each read in chunks of ids: a few queries, whatever the number of allocations.

## Consequences

- An application — and the next phase — can see precisely what a reversal would have to correct before anything is changed.
- Correcting consumed binary carry, and the commissions paid on it, is deferred to Phase 3.4B and later; a pairing run still refuses a consumed reversal as before.
