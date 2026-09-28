<?php

declare(strict_types=1);

namespace PandaPanel\Forms\Components;

use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use PandaPanel\Forms\Enums\FieldType;
use Stringable;

/**
 * An amount of money, typed the way the locale writes it.
 *
 * The amount crosses the wire as a decimal *string* in both directions, never
 * as a float: `0.1 + 0.2` is not `0.3` in either PHP or JavaScript, and a
 * price that drifted by a fraction of a cent on its way through a form is a
 * bug nobody would find until the books did not balance. Rounding here is
 * done on the digits, for the same reason.
 *
 * The browser groups thousands and places the currency symbol; the value it
 * submits is always the plain amount — `12500.50`, not `Rp 12.500,50`.
 */
final class MoneyInput extends Field
{
    /**
     * Currencies written without a fractional part.
     *
     * ISO 4217's zero-decimal list, plus IDR: the standard gives the rupiah
     * two decimals, but nobody prices anything in sen, and a form showing
     * `Rp 150.000,00` for every amount is noise. `decimals(2)` restores the
     * standard where it matters.
     */
    private const WHOLE_UNITS = [
        'BIF', 'CLP', 'DJF', 'GNF', 'IDR', 'ISK', 'JPY', 'KMF', 'KRW',
        'PYG', 'RWF', 'UGX', 'UYI', 'VND', 'VUV', 'XAF', 'XOF', 'XPF',
    ];

    /** Currencies whose minor unit is a thousandth. */
    private const THOUSANDTHS = ['BHD', 'IQD', 'JOD', 'KWD', 'LYD', 'OMR', 'TND'];

    private ?string $currency = null;

    private ?int $decimals = null;

    private ?string $locale = null;

    private bool $minorUnits = false;

    private int|float|null $min = null;

    private int|float|null $max = null;

    public function type(): FieldType
    {
        return FieldType::Money;
    }

    /**
     * The ISO 4217 code. Defaults to `panda-panel.forms.currency`.
     */
    public function currency(string $currency): self
    {
        $this->currency = $this->currencyCode($currency, 'currency()');

        return $this;
    }

    /**
     * Digits after the decimal separator — both what the control accepts
     * and what validation allows. Defaults to what the currency uses.
     */
    public function decimals(int $decimals): self
    {
        if ($decimals < 0 || $decimals > 4) {
            throw new InvalidArgumentException(
                "The field [{$this->getName()}] was given {$decimals} decimals; a currency has between 0 and 4.",
            );
        }

        $this->decimals = $decimals;

        return $this;
    }

    /**
     * The locale the amount is written in, as a BCP 47 tag such as `id-ID`.
     *
     * Null follows the panel's own locale, which is almost always right: the
     * separators a person expects are the ones of the language they are
     * reading the form in.
     */
    public function locale(?string $locale): self
    {
        $this->locale = $locale;

        return $this;
    }

    /**
     * Stores the amount as an integer count of the currency's minor unit —
     * cents for USD — rather than as a decimal.
     *
     * The form still shows and validates the amount people type: `min()` and
     * `max()` stay in whole units. Only the value written to the record, and
     * read back from it, is converted.
     */
    public function storedAsMinorUnits(bool $minorUnits = true): self
    {
        $this->minorUnits = $minorUnits;

        return $this;
    }

    public function min(int|float|null $min): self
    {
        $this->min = $min;

        return $this;
    }

    public function max(int|float|null $max): self
    {
        $this->max = $max;

        return $this;
    }

    public function getCurrency(): string
    {
        return $this->currency ?? $this->currencyCode(
            config('panda-panel.forms.currency', 'USD'),
            'the panda-panel.forms.currency config value',
        );
    }

    public function getDecimals(): int
    {
        if ($this->decimals !== null) {
            return $this->decimals;
        }

        $currency = $this->getCurrency();

        return match (true) {
            in_array($currency, self::WHOLE_UNITS, true) => 0,
            in_array($currency, self::THOUSANDTHS, true) => 3,
            default => 2,
        };
    }

    public function isStoredAsMinorUnits(): bool
    {
        return $this->minorUnits;
    }

    /**
     * Rounds and, for a minor-unit column, converts the amount on its way to
     * the record. A hook set with `mutateUsing()` still runs, and receives
     * the value this produced.
     */
    public function mutate(mixed $value, ?Model $record): mixed
    {
        $amount = self::amount($value);

        if ($amount !== null) {
            $amount = self::round($amount, $this->getDecimals());

            $value = $this->minorUnits
                ? (int) self::shift($amount, $this->getDecimals())
                : $amount;
        } elseif ($value === '') {
            $value = null;
        }

        return parent::mutate($value, $record);
    }

    /**
     * @return list<string>
     */
    protected function typeRules(): array
    {
        // `decimal` caps the digits after the separator, so an amount the
        // form would have to round is refused rather than silently changed.
        $rules = ['numeric', 'decimal:0,'.$this->getDecimals()];

        if ($this->min !== null) {
            $rules[] = 'min:'.$this->min;
        }

        if ($this->max !== null) {
            $rules[] = 'max:'.$this->max;
        }

        return $rules;
    }

    protected function castForForm(mixed $value): ?string
    {
        $amount = self::amount($value);

        if ($amount === null) {
            return null;
        }

        if ($this->minorUnits) {
            $amount = self::shift($amount, -$this->getDecimals());
        }

        return self::round($amount, $this->getDecimals());
    }

    /**
     * @return array<string, mixed>
     */
    protected function extraArray(): array
    {
        return [
            'currency' => $this->getCurrency(),
            'decimals' => $this->getDecimals(),
            'locale' => $this->locale,
            'min' => $this->min,
            'max' => $this->max,
        ];
    }

    private function currencyCode(mixed $currency, string $source): string
    {
        $code = is_string($currency) ? strtoupper(trim($currency)) : '';

        if (preg_match('/^[A-Z]{3}$/', $code) !== 1) {
            throw new InvalidArgumentException(sprintf(
                'The field [%s] was given [%s] by %s; a currency is an ISO 4217 code such as IDR or USD.',
                $this->getName(),
                is_scalar($currency) ? (string) $currency : get_debug_type($currency),
                $source,
            ));
        }

        return $code;
    }

    /**
     * Any numeric value as a plain decimal string — `-12500.5` — or null.
     *
     * Database drivers hand a decimal column back as a string, an integer
     * column as an int, and a float column as a float; a request carries a
     * string. All four end up in the one shape the rest of this class does
     * arithmetic on.
     */
    private static function amount(mixed $value): ?string
    {
        if (is_int($value)) {
            return (string) $value;
        }

        if (is_float($value)) {
            if (! is_finite($value)) {
                return null;
            }

            // Ten places is past any currency's precision and short of the
            // noise a binary float carries in its last digits.
            return self::amount(number_format($value, 10, '.', ''));
        }

        if ($value instanceof Stringable) {
            $value = (string) $value;
        }

        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        if (preg_match('/^([+-]?)(\d*)(?:\.(\d*))?$/', $value, $matches) === 1) {
            $digits = $matches[2].($matches[3] ?? '');

            // A bare sign or a lone `.` matches the pattern and is not a number.
            if ($digits === '') {
                return null;
            }

            return self::assemble($matches[1] === '-', ltrim($matches[2], '0'), rtrim($matches[3] ?? '', '0'));
        }

        // `1e3` and friends are numeric too, and rare enough to go through
        // a float without anyone noticing the precision.
        return is_numeric($value) ? self::amount((float) $value) : null;
    }

    /**
     * Rounds half away from zero to exactly `$decimals` places.
     */
    private static function round(string $amount, int $decimals): string
    {
        [$negative, $integer, $fraction] = self::parts($amount);

        if (strlen($fraction) > $decimals) {
            $up = $fraction[$decimals] >= '5';
            $digits = $integer.substr($fraction, 0, $decimals);

            if ($up) {
                $digits = self::increment($digits);
            }

            $integer = substr($digits, 0, strlen($digits) - $decimals);
            $fraction = substr($digits, strlen($digits) - $decimals);
        }

        $fraction = str_pad($fraction, $decimals, '0');
        $integer = ltrim($integer, '0') === '' ? '0' : ltrim($integer, '0');
        $zero = trim($integer.$fraction, '0') === '';

        return ($negative && ! $zero ? '-' : '').$integer.($decimals > 0 ? '.'.$fraction : '');
    }

    /**
     * Moves the decimal point `$places` to the right, or left when negative:
     * whole units to minor units and back.
     */
    private static function shift(string $amount, int $places): string
    {
        [$negative, $integer, $fraction] = self::parts($amount);

        $digits = $integer.$fraction;
        $point = strlen($integer) + $places;

        if ($point < 0) {
            $digits = str_repeat('0', -$point).$digits;
            $point = 0;
        }

        $digits = str_pad($digits, $point, '0');

        return self::assemble(
            $negative,
            ltrim(substr($digits, 0, $point), '0'),
            rtrim(substr($digits, $point), '0'),
        );
    }

    /**
     * @return array{bool, string, string}
     */
    private static function parts(string $amount): array
    {
        $negative = str_starts_with($amount, '-');
        [$integer, $fraction] = array_pad(explode('.', ltrim($amount, '+-'), 2), 2, '');

        return [$negative, $integer, $fraction];
    }

    private static function assemble(bool $negative, string $integer, string $fraction): string
    {
        $integer = $integer === '' ? '0' : $integer;
        $zero = trim($integer.$fraction, '0') === '';

        return ($negative && ! $zero ? '-' : '').$integer.($fraction !== '' ? '.'.$fraction : '');
    }

    /**
     * Adds one to a string of digits, carrying as far as it has to.
     */
    private static function increment(string $digits): string
    {
        for ($i = strlen($digits) - 1; $i >= 0; $i--) {
            if ($digits[$i] !== '9') {
                return substr($digits, 0, $i).((int) $digits[$i] + 1).str_repeat('0', strlen($digits) - $i - 1);
            }
        }

        return '1'.str_repeat('0', strlen($digits));
    }
}
