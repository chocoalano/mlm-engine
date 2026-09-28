# ADR-010: Quantities are exact decimals stored as whole millionths

## Status

Accepted — Phase 2.0. Amended in Phase 2.1: `Quantity` became unbounded and its millionths conversion exact at any size; the size limit moved to the stored entry.

## Context

Volume quantities are decimals — `25.5`, `0.125` — that later calculations add, compare and apportion. They must add up exactly on every supported database and never pass through a PHP float, which cannot represent most decimals (`0.1 + 0.2` is `0.30000000000000004`).

The obvious column, `DECIMAL(20, 6)`, is exact on MySQL and PostgreSQL but not on SQLite. Measured on the suite's SQLite: Laravel declares it `numeric`, SQLite stores `0.1` as a REAL, `12345678901234.123456` reads back as `12345678901234.123`, and `SUM` of `0.1` and `0.2` returns `0.30000000000000004`.

Phase 2.0 converted a `Quantity` to millionths as a PHP `int`. A `Quantity` read from a database sum can exceed 64 bits, so that conversion could not return its own value — an exactness leak in the value object's contract.

## Decision

- **Storage.** `quantity_millionths BIGINT`: the quantity multiplied by 10⁶, as an integer. Integer storage and `SUM` are exact on SQLite, MySQL and PostgreSQL alike. The scale is six decimal places.
- **PHP.** `PandaBear\Mlm\Volume\Quantity`, a small immutable value object holding the canonical decimal string: no leading zeros, no trailing fractional zeros, no sign on zero — `"25"`, `"25.5"`, `"-0.125"`. It validates, negates, compares, and converts to and from millionths. It does no general arithmetic and carries no business rules. No decimal library was added.
- **Input.** `Quantity::of()` accepts a decimal string or an integer. A float is refused; so are `""`, `".5"`, `"5."`, `"+5"`, `"1e3"`, `"1,5"` and surrounding whitespace. More than six decimal places is refused, never rounded — the package has no rounding policy to impose. Trailing zeros are not precision, so `"1.1000000"` is `"1.1"`.
- **Two ranges, kept apart.**
  - *What a `Quantity` can represent:* any size. `toMillionths()` returns an exact signed integer **string** — `"25500000"` for `25.5` — and `fromMillionths()` accepts one, so a value round-trips exactly however large it is. Never a PHP `int`.
  - *What one `VolumeEntry` can store:* at most `VolumeEntry::MAX_INTEGER_DIGITS` (12) integer digits — up to 999,999,999,999.999999, an 18-digit count of millionths that always fits the signed 64-bit column. `RecordVolume` refuses a larger quantity on construction. A reversal negates a stored entry, so it is within the bound by construction.
- **Persistence.** The millionths string goes into the column as a string — never through `(int)` or a float — and each database stores it as an integer. Verified against SQLite in the test suite (`typeof` is `integer`), and against MySQL 9.6 and PostgreSQL 18.4 with a one-off probe: ±999,999,999,999,999,999 round-trips exactly.
- **Output.** The model exposes `quantity` as a `Quantity`, never a float. Totals come back from the database as integer millionths. On MySQL and PostgreSQL a sum beyond 64 bits comes back as an exact string, and `fromMillionths()` reads it exactly — also verified by the probe.

## Consequences

- Totals are exact: three entries of `0.1` total `0.3`, on SQLite too, and a total larger than any single entry remains exact end to end.
- The stored column is not human-readable as a decimal (`25500000` is `25.5`); its name says so.
- A sum beyond 64 bits of millionths (about 9.2 trillion units) overflows `SUM` on SQLite; MySQL and PostgreSQL widen the sum.
- Code that needs a PHP integer from `toMillionths()` must convert deliberately and own the range question.
- Any later amount the package stores — commission, for instance — should face the same question on the same evidence rather than reaching for `DECIMAL`.
