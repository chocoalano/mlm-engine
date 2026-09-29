# ADR-025: A reversed source undoes the binary pairs it fed and gives the other side its carry back, in a journal

## Status

Accepted — Phase 3.4B. Completed in Phase 3.4C: the journal's commissions are corrected financially — the undone share of their stored amount, before or after posting (ADR-026). Binary structure, pairing, carry, consumed-source reversal and financial correction are complete.

## Context

Binary carry is kept source by source, and every pair records which lots it drew on (ADR-023). When a source entry that fed a pair is reversed later, the pair was built partly on business that no longer exists. Until now a pairing run refused such a reversal outright (`BinaryPairingCorrectionRequired`), and the read-only analyzer (ADR-024) could only show what it reached. The binary state has to be corrected — without rewriting the runs, results and commissions that already happened, and before any money is corrected.

## Decision

- **History is immutable; a journal explains it.** Pairing results, allocations, runs and commissions are never changed. A correction is a new `BinaryPairingCorrection` — so much of one historical allocation stopped counting because its source was reversed — with `BinaryPairingRestoration` rows for what it gave back. Both are written once, by the pairing state transition, and read-only through Eloquent.
- **Net consumption.** An allocation still counts its quantity less what corrections released of it: invalidated (its own source reversed) or restored (the other side's source reversed). A lot's net consumption is the sum over its allocations. For a lot not reversed, remainder + net consumption = its quantity. A lot a reversal took back holds nothing and has net consumption zero. The analyzer (ADR-024) reports allocated, invalidated, restored and net quantities, and a lot's state from its net consumption.
- **Undoing a pair.** When a reversal reaches a lot, every allocation of it that still counts is invalidated whole. The same quantity goes back to the other side of that same pairing, from the lots that side drew on, **newest source first** — the FIFO consumption undone in reverse, so the oldest valid consumption stands. An allocation gives back at most what it still counts. The lot's own open carry is taken back as before. If the other side cannot give back exactly the quantity undone, or would have to give back into a lot whose source is itself reversed, the history is refused as corrupt (`InvalidBinaryCorrection`); nothing is invented.
- **Given-back carry pairs again.** Results gain `left_restored` and `right_restored`: what corrections gave back to each side in the run. Available = carry before + added + restored − reversed. The quantity given back is carry like any other — in the run the reversal falls in, it can pair again, oldest source first. Earlier results read `0`.
- **In the run the reversal falls in.** Corrections are planned by the calculation and applied by its state transition, in the run whose range holds the reversal's moment — not when the reversal is recorded — so each component's cursor stays chronological. Each component corrects its own state, in its own run. Reversals in one run are undone in a stable order — by moment, then reversal id, then original id — so a pair both of whose sources are reversed is undone once: the second reversal finds its allocation already released and takes back what it holds as ordinary carry.
- **Atomic and replay-safe.** Corrections share the run's serializable transaction and retries (ADR-023). The transition checks that every stored lot it changes, and what earlier corrections released of every allocation it undoes, is still what the calculation read. A replayed run applies nothing. A reversal undoes an allocation once: `UNIQUE(reversal, invalidated allocation)`.
- **No money yet.** A correction records the commission of the pairing it undoes, when there was one, for the financial correction to come; nothing here cancels or reverses a commission, writes an adjustment, or touches the ledger or wallets. A pairing whose award rounded to nothing is corrected all the same.
- **Late reversals.** A reversal whose moment falls in a range the component has already calculated is never taken in, as for any late volume (ADR-023). Recovering late events is a later design.

## Consequences

- A pairing run no longer stops at a reversal of paid-out carry: the binary state is corrected, exactly and explainably, and `BinaryPairingCorrectionRequired` is gone.
- Commissions earned on undone pairs still stand until `CommissionAdjustmentEngine::processBinaryReversal()` corrects them from the journal (ADR-026); until then they cannot be posted.
