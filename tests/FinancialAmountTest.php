<?php

declare(strict_types=1);

namespace PandaBear\Mlm\Tests;

use PandaBear\Mlm\Exceptions\InvalidFinancialAmount;
use PandaBear\Mlm\Finance\FinancialAmount;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase as PHPUnitTestCase;

/**
 * Money as an exact decimal: canonical, six places, never a float, never
 * rounded, and exact at any size.
 */
final class FinancialAmountTest extends PHPUnitTestCase
{
    /**
     * @return array<string, array{string|int, string}>
     */
    public static function canonicalForms(): array
    {
        return [
            'a whole number' => ['100', '100'],
            'leading zeros' => ['00100', '100'],
            'a trailing fractional zero' => ['100.50', '100.5'],
            'six places' => ['-25.123456', '-25.123456'],
            'one millionth' => ['0.000001', '0.000001'],
            'zero' => ['0', '0'],
            'negative zero' => ['-0.000', '0'],
            'zeros beyond six places are not precision' => ['1.1234560', '1.123456'],
            'an integer' => [42, '42'],
            'a negative integer' => [-7, '-7'],
            'beyond 64 bits' => ['123456789012345678901234567890.5', '123456789012345678901234567890.5'],
        ];
    }

    #[DataProvider('canonicalForms')]
    public function test_an_amount_is_kept_in_its_canonical_form(string|int $given, string $canonical): void
    {
        $this->assertSame($canonical, FinancialAmount::of($given)->value());
        $this->assertSame($canonical, (string) FinancialAmount::of($given));
    }

    /**
     * @return array<string, array{mixed, string}>
     */
    public static function refusals(): array
    {
        return [
            'seven places' => ['1.1234567', 'more than 6 decimal places'],
            'an exponent' => ['1e5', 'not a plain decimal'],
            'a plus sign' => ['+1', 'not a plain decimal'],
            'leading whitespace' => [' 1', 'not a plain decimal'],
            'trailing whitespace' => ['1 ', 'not a plain decimal'],
            'a trailing newline' => ["100\n", 'not a plain decimal'],
            'no fraction digits' => ['1.', 'not a plain decimal'],
            'no integer digits' => ['.5', 'not a plain decimal'],
            'grouping' => ['1,000', 'not a plain decimal'],
            'empty' => ['', 'not a plain decimal'],
            'NaN text' => ['NaN', 'not a plain decimal'],
            'Infinity text' => ['INF', 'not a plain decimal'],
            'a float' => [1.5, 'a float cannot hold most decimals exactly'],
            'a whole float' => [100.0, 'a float cannot hold most decimals exactly'],
            'NAN' => [NAN, 'a float cannot hold most decimals exactly'],
            'INF' => [INF, 'a float cannot hold most decimals exactly'],
            'null' => [null, 'must be a decimal string or an integer'],
            'a boolean' => [true, 'must be a decimal string or an integer'],
        ];
    }

    #[DataProvider('refusals')]
    public function test_anything_but_an_exact_decimal_is_refused(mixed $given, string $reason): void
    {
        $this->expectException(InvalidFinancialAmount::class);
        $this->expectExceptionMessage($reason);

        FinancialAmount::of($given);
    }

    public function test_millionths_convert_both_ways_at_any_size(): void
    {
        $this->assertSame('100.5', FinancialAmount::fromMillionths('100500000')->value());
        $this->assertSame('-0.000001', FinancialAmount::fromMillionths(-1)->value());
        $this->assertSame('0', FinancialAmount::fromMillionths(0)->value());
        $this->assertSame('100500000', FinancialAmount::of('100.5')->toMillionths());
        $this->assertSame('-1', FinancialAmount::of('-0.000001')->toMillionths());
        $this->assertSame('0', FinancialAmount::of('0')->toMillionths());

        $large = '12345678901234567890.123456';
        $this->assertSame('12345678901234567890123456', FinancialAmount::of($large)->toMillionths());
        $this->assertSame($large, FinancialAmount::fromMillionths('12345678901234567890123456')->value());

        foreach (['1.5', 'abc', '', '1e3', "100\n"] as $invalid) {
            try {
                FinancialAmount::fromMillionths($invalid);
                $this->fail("\"{$invalid}\" was read as millionths.");
            } catch (InvalidFinancialAmount $exception) {
                $this->assertStringContainsString('not a whole number of millionths', $exception->getMessage());
            }
        }
    }

    public function test_sign_and_negation(): void
    {
        $this->assertTrue(FinancialAmount::zero()->isZero());
        $this->assertFalse(FinancialAmount::zero()->isPositive());
        $this->assertFalse(FinancialAmount::zero()->isNegative());
        $this->assertTrue(FinancialAmount::of('0.000001')->isPositive());
        $this->assertTrue(FinancialAmount::of('-0.000001')->isNegative());

        $this->assertSame('-100.5', FinancialAmount::of('100.5')->negate()->value());
        $this->assertSame('100.5', FinancialAmount::of('-100.5')->negate()->value());
        $this->assertSame('0', FinancialAmount::zero()->negate()->value());
    }

    /**
     * @return array<string, array{string, string, int}>
     */
    public static function comparisons(): array
    {
        return [
            'equal' => ['100.5', '100.50', 0],
            'smaller' => ['99.999999', '100', -1],
            'larger' => ['100.000001', '100', 1],
            'longer is larger' => ['1000', '999.999999', 1],
            'negative below positive' => ['-1000', '1', -1],
            'more negative is smaller' => ['-1000', '-999', -1],
            'beyond 64 bits' => ['92233720368547758070', '92233720368547758069.999999', 1],
            'not as text' => ['9', '10', -1],
        ];
    }

    #[DataProvider('comparisons')]
    public function test_amounts_compare_exactly(string $a, string $b, int $expected): void
    {
        $this->assertSame($expected, FinancialAmount::of($a)->compare(FinancialAmount::of($b)));
        $this->assertSame(-$expected, FinancialAmount::of($b)->compare(FinancialAmount::of($a)));
        $this->assertSame($expected === 0, FinancialAmount::of($a)->equals(FinancialAmount::of($b)));
    }

    /**
     * @return array<string, array{string, string, string}>
     */
    public static function additions(): array
    {
        return [
            'simple' => ['100', '0.5', '100.5'],
            'to zero' => ['-100', '100', '0'],
            'a carry into the integer' => ['0.999999', '0.000001', '1'],
            'a carry across a limb' => ['999999999999.999999', '0.000001', '1000000000000'],
            'two largest postings' => ['9223372036854.775807', '9223372036854.775807', '18446744073709.551614'],
            'mixed signs beyond 64 bits' => ['-100000000000000000000', '99999999999999999999.999999', '-0.000001'],
            'a borrow across limbs' => ['1000000000000000000000', '-0.000001', '999999999999999999999.999999'],
            'cancelling beyond 64 bits' => ['12345678901234567890', '-12345678901234567890', '0'],
            'negative beyond 64 bits' => ['-9223372036854.775807', '-9223372036854.775807', '-18446744073709.551614'],
        ];
    }

    #[DataProvider('additions')]
    public function test_addition_is_exact(string $a, string $b, string $sum): void
    {
        $this->assertSame($sum, FinancialAmount::of($a)->add(FinancialAmount::of($b))->value());
        $this->assertSame($sum, FinancialAmount::of($b)->add(FinancialAmount::of($a))->value());
    }

    public function test_addition_matches_digit_by_digit_arithmetic_at_every_size(): void
    {
        mt_srand(2027);

        for ($i = 0; $i < 500; $i++) {
            [$a, $b] = [$this->randomMillionths(), $this->randomMillionths()];
            $sum = FinancialAmount::fromMillionths($a)->add(FinancialAmount::fromMillionths($b));

            $this->assertSame(self::referenceSum($a, $b), $sum->toMillionths(), "{$a} + {$b}");
            $this->assertSame($a, $sum->add(FinancialAmount::fromMillionths($b)->negate())->toMillionths(), "({$a} + {$b}) - {$b}");
        }
    }

    public function test_a_sum_is_exact_beyond_64_bits(): void
    {
        $largest = FinancialAmount::of('9223372036854.775807');

        $this->assertSame('0', FinancialAmount::sum([])->value());
        $this->assertSame('27670116110564.327421', FinancialAmount::sum([$largest, $largest, $largest])->value());
        $this->assertSame('27670116110564327421', FinancialAmount::sum([$largest, $largest, $largest])->toMillionths());
        $this->assertSame('0', FinancialAmount::sum([$largest, $largest, $largest->negate(), $largest->negate()])->value());
        $this->assertSame('0.3', FinancialAmount::sum((static function () {
            yield FinancialAmount::of('0.1');
            yield FinancialAmount::of('0.2');
        })())->value());
    }

    public function test_only_amounts_are_summed(): void
    {
        $this->expectException(InvalidFinancialAmount::class);

        FinancialAmount::sum([FinancialAmount::of('1'), '2']);
    }

    private function randomMillionths(): string
    {
        $digits = (string) mt_rand(1, 9);

        for ($length = mt_rand(0, 40); $length > 0; $length--) {
            $digits .= (string) mt_rand(0, 9);
        }

        return (mt_rand(0, 1) === 1 ? '-' : '').$digits;
    }

    /**
     * Schoolbook signed addition, one digit at a time: independent of the
     * implementation under test.
     */
    private static function referenceSum(string $a, string $b): string
    {
        $magnitude = static fn (string $n): string => ltrim($n, '-');
        $negative = static fn (string $n): bool => str_starts_with($n, '-');
        $greater = static fn (string $x, string $y): int => (strlen($x) <=> strlen($y)) ?: (strcmp($x, $y) <=> 0);

        $digitwise = static function (string $x, string $y, int $sign): string {
            $x = strrev($x);
            $y = strrev($y);
            $out = '';
            $carry = 0;

            for ($i = 0; $i < max(strlen($x), strlen($y)); $i++) {
                $digit = (int) ($x[$i] ?? '0') + $sign * (int) ($y[$i] ?? '0') + $carry;
                $carry = $digit < 0 ? -1 : intdiv($digit, 10);
                $out .= (string) (($digit + 10) % 10);
            }

            if ($carry > 0) {
                $out .= (string) $carry;
            }

            return ltrim(strrev($out), '0') ?: '0';
        };

        if ($negative($a) === $negative($b)) {
            $sum = $digitwise($magnitude($a), $magnitude($b), 1);

            return $negative($a) && $sum !== '0' ? '-'.$sum : $sum;
        }

        $order = $greater($magnitude($a), $magnitude($b));

        if ($order === 0) {
            return '0';
        }

        [$big, $small, $sign] = $order > 0 ? [$a, $b, $negative($a)] : [$b, $a, $negative($b)];

        return ($sign ? '-' : '').$digitwise($magnitude($big), $magnitude($small), -1);
    }
}
