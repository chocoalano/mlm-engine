# ADR-010: Quantities are exact decimals stored as whole millionths

## Status

Accepted — Phase 2.0.

## Context

Volume quantities are decimals — `25.5`, `0.125` — that later calculations add, compare and apportion. They must add up exactly on every supported database and never pass through a PHP float, which cannot represent most decimals (`0.1 + 0.2` is `0.30000000000000004`).

The obvious column, `DECIMAL(20, 6)`, is exact on MySQL and PostgreSQL but not on SQLite. Measured on the suite's SQLite: Laravel declares it `numeric`, SQLite stores `0.1` as a REAL, `12345678901234.123456` reads back as `12345678901234.123`, and `SUM` of `0.1` and `0.2` returns `0.30000000000000004`.

## Decision

- **Storage.** `quantity_millionths BIGINT`: the quantity multiplied by 10⁶, as an integer. Integer storage and `SUM` are exact on SQLite, MySQL and PostgreSQL alike. The scale is six decimal places.
- **PHP.** `PandaBear\Mlm\Volume\Quantity`, a small immutable value object holding the canonical decimal string: no leading zeros, no trailing fractional zeros, no sign on zero — `"25"`, `"25.5"`, `"-0.125"`. It validates, negates, compares, and converts to and from millionths. It does no general arithmetic and carries no business rules. No decimal library was added.
- **Input.** `Quantity::of()` accepts a decimal string or an integer. A float is refused; so are `""`, `".5"`, `"5."`, `"+5"`, `"1e3"`, `"1,5"` and surrounding whitespace. More than six decimal places is refused, never rounded — the package has no rounding policy to impose. Trailing zeros are not precision, so `"1.1000000"` is `"1.1"`. A recorded quantity has at most 12 integer digits (up to 999,999,999,999.999999), so it always fits a 64-bit count of millionths.
- **Output.** The model exposes `quantity` as a `Quantity`, never a float. Totals come back from the database as integer millionths — as a string on MySQL and PostgreSQL, which are read exactly even beyond 64 bits.

## Consequences

- Totals are exact: three entries of `0.1` total `0.3`, on SQLite too.
- The stored column is not human-readable as a decimal (`25500000` is `25.5`); its name says so.
- A sum beyond 64 bits of millionths (about 9.2 trillion units) overflows `SUM` on SQLite; MySQL and PostgreSQL widen the sum.
- Any later amount the package stores — commission, for instance — should face the same question on the same evidence rather than reaching for `DECIMAL`.
