# ADR-020: Proportional awards are quantity × money per unit, computed exactly and rounded per candidate by an explicit mode

## Status

Accepted — Phase 3.0.

## Context

The first built-in strategies pay fixed awards (ADR-019) because proportional pay needs arithmetic the package did not have: a six-decimal business quantity (ADR-010) times a six-decimal amount of money (ADR-017) can need twelve decimal places, and money is kept to six. Something must decide what happens to the rest, and no rounding policy existed — nor any global one the package could honestly assume.

## Decision

- **What proportional means.** An award is the source entry's quantity times a configured `unit_amount`: money, in the component's currency, per one unit of the business measurement. It is not a percentage, a ratio or a share of revenue — a volume entry measures business, it is not money. Percentage-of-money awards wait for a monetary business-event domain.
- **Exact arithmetic, in integers.** With Q the quantity in millionths and U the unit amount in millionths, Q × U is the award in millionths of a millionth — twelve decimal places, exact. The award in financial millionths is Q × U / 1,000,000, rounded by the configured mode. The product is computed by a small schoolbook multiplication on decimal digits, in seven-digit limbs: no float anywhere, no 64-bit limit, no BCMath or GMP.
- **Finance stays independent.** `FinancialAmount` gains no multiplication by a quantity, and the volume `Quantity` none by money: the two meet only in the commission strategies' support layer (`ProportionalAwardCalculator`).
- **Four explicit rounding modes.** `FinancialRoundingMode` is exactly `toward_zero` (drop the remainder), `away_from_zero` (add a millionth whenever anything remains), `half_up` (nearest; an exact half goes up) and `half_even` (nearest; an exact half goes to the even millionth). Each decides from the whole millionths and the remainder in millionths of a millionth, with integer comparisons. Values are spelled exactly: no aliases, case changes or trimming. For 0.0000005 they give 0, 0.000001, 0.000001 and 0; for 0.0000015, 0.000001, 0.000002, 0.000002 and 0.000002.
- **Rounding is plan configuration, and mandatory.** Every proportional component names its `rounding`; a missing or misspelled one blocks `markValidated()`, and a stored definition corrupted afterwards stops the run. There is no default, and nothing is taken from configuration, the environment or the currency: two plans may round differently, and every currency keeps six financial decimals — settlement to a currency's minor unit is a separate concern.
- **Rounded per candidate.** Each award — one source entry at one rewarded depth — is rounded on its own, before it becomes a candidate. Nothing is summed first, per member, per depth or per run, and then rounded: the candidate is the rounding granularity, so two awards of 0.0000006 pay 0.000001 each, not 0.000001 together.
- **Rounded to zero is no award.** A positive product that rounds to zero produces no candidate — a valid outcome, not an error — and no zero commission. `away_from_zero` never rounds a positive product to zero.
- **The posting bound applies to the rounded award, not the rate.** `unit_amount` is exact, strictly positive and at most six decimal places, but not bounded by one ledger posting: it is a rate. The rounded award is a `CommissionCandidate`, and one larger than a posting — including one that rounding up pushes past the largest posting — fails the whole run atomically. Nothing is capped or split.
- **Two proportional siblings.** `direct-sponsor.proportional` takes exactly `volume_type`, `source_type`, `minimum_quantity`, `unit_amount` and `rounding`; `unilevel.proportional` takes `volume_type`, `source_type`, `minimum_quantity`, `rounding` and `levels` of exactly `{depth, unit_amount}`, with one rounding mode for every depth. Both are registered like the fixed strategies and reuse, unchanged, their eligible-entry query, minimum quantity, reversal cutoff, historical sponsor attribution, physical depths without compression, rule refusal, candidate keys (`volume-entry:<id>:depth:<d>`) and earned-at moments. Only the amount differs: the arithmetic adds no database query.
- **An auditable amount.** Each proportional trace records the calculation — `quantity`, `unit_amount`, `exact_amount` (the unrounded product, as canonical text of up to twelve decimal places, since it is not money), `rounding`, whether anything was `rounded`, and the final `amount`, equal to the commission's — so a posted amount can be reconstructed from its trace alone.
- **Nothing else changes.** Fixed strategies keep their parameters, amounts, traces and keys. The calculation engine, runs, commissions, their lifecycle, the poster, the ledger and wallets are unchanged, and there is no migration. A reversal in a later range is still not clawed back.

## Consequences

- Plans can pay in proportion to the business they reward, exactly, with a rounding policy they choose and can see in every trace.
- Per-candidate rounding makes totals depend on candidate granularity — the price of one auditable, reproducible amount per source entry and depth.
- Exact arithmetic costs about 16 µs per award in PHP; the run's cost stays dominated by its reads.
- Percentage-of-money awards, currency settlement precision, clawback and aggregate awards remain later designs.
