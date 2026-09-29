<?php

declare(strict_types=1);

namespace PandaBear\Mlm\Tests;

use InvalidArgumentException;
use PandaBear\Mlm\Commission\Strategies\Support\ProportionalAwardCalculator;
use PandaBear\Mlm\Finance\FinancialAmount;
use PandaBear\Mlm\Finance\FinancialRoundingMode;
use PandaBear\Mlm\Volume\Quantity;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase as PHPUnitTestCase;

/**
 * A quantity times an amount per unit, exactly, rounded to financial
 * millionths by an explicit mode — on digits, at any size, never floats.
 */
final class ProportionalAwardCalculatorTest extends PHPUnitTestCase
{
    /**
     * Quantity, amount per unit, the exact product, and the rounded amount
     * under toward_zero, away_from_zero, half_up and half_even.
     *
     * @return array<string, array{string, string, string, array{string, string, string, string}}>
     */
    public static function products(): array
    {
        return [
            'a whole product' => ['125.5', '2', '251', ['251', '251', '251', '251']],
            'a fractional product within six places' => ['10.25', '1.5', '15.375', ['15.375', '15.375', '15.375', '15.375']],
            'an exact product, every mode alike' => ['2', '1.5', '3', ['3', '3', '3', '3']],
            'an exact half, even' => ['0.000001', '0.5', '0.0000005', ['0', '0.000001', '0.000001', '0']],
            'an exact half, odd' => ['0.000003', '0.5', '0.0000015', ['0.000001', '0.000002', '0.000002', '0.000002']],
            'below a half' => ['0.000001', '0.4', '0.0000004', ['0', '0.000001', '0', '0']],
            'above a half' => ['0.000001', '0.6', '0.0000006', ['0', '0.000001', '0.000001', '0.000001']],
            'six places by six places' => ['1.234567', '2.345678', '2.895896651426', ['2.895896', '2.895897', '2.895897', '2.895897']],
            'the smallest remainder' => ['0.000001', '1.000001', '0.000001000001', ['0.000001', '0.000002', '0.000001', '0.000001']],
            'the largest remainder' => ['0.999999', '0.999999', '0.999998000001', ['0.999998', '0.999999', '0.999998', '0.999998']],
            'an exact half above whole units, odd' => ['1.000001', '0.5', '0.5000005', ['0.5', '0.500001', '0.500001', '0.5']],
            'an exact half above whole units, even' => ['1.000003', '0.5', '0.5000015', ['0.500001', '0.500002', '0.500002', '0.500002']],
            'a zero quantity' => ['0', '5', '0', ['0', '0', '0', '0']],
        ];
    }

    /**
     * @param  array{string, string, string, string}  $rounded
     */
    #[DataProvider('products')]
    public function test_each_mode_rounds_the_exact_product(string $quantity, string $unit, string $exact, array $rounded): void
    {
        foreach (FinancialRoundingMode::cases() as $index => $mode) {
            $result = ProportionalAwardCalculator::calculate(Quantity::of($quantity), FinancialAmount::of($unit), $mode);

            $this->assertSame($exact, $result->exactAmount, "{$quantity} × {$unit}");
            $this->assertSame($rounded[$index], $result->amount->value(), "{$quantity} × {$unit}, {$mode->value}");
            $this->assertSame($mode, $result->rounding);
            $this->assertSame(strlen(explode('.', $exact.'.')[1]) > 6, $result->rounded, "{$quantity} × {$unit} rounded");
        }
    }

    public function test_the_exact_amount_is_canonical_text(): void
    {
        $exact = static fn (string $quantity, string $unit): string => ProportionalAwardCalculator::calculate(Quantity::of($quantity), FinancialAmount::of($unit), FinancialRoundingMode::HalfEven)->exactAmount;

        $this->assertSame('3', $exact('2', '1.5'));
        $this->assertSame('1.5', $exact('1', '1.5'));
        $this->assertSame('0.0000015', $exact('0.000003', '0.5'));
        $this->assertSame('0.000000000001', $exact('0.000001', '0.000001'));
        $this->assertSame('0', $exact('0', '0.000001'));
        $this->assertSame('100', $exact('000100.000', '1'));
    }

    public function test_products_beyond_64_bits_stay_exact(): void
    {
        $this->assertSame('987654321987654320012345678012345679', ProportionalAwardCalculator::multiply('999999999999999999', '987654321987654321'));
        $this->assertSame('85070591730234615847396907784232501249', ProportionalAwardCalculator::multiply('9223372036854775807', '9223372036854775807'));
        $this->assertSame('0', ProportionalAwardCalculator::multiply('0', '9223372036854775807'));
        $this->assertSame('10000000000000000000000000000000000000', ProportionalAwardCalculator::multiply('10000000000000000000', '1000000000000000000'));

        $result = ProportionalAwardCalculator::calculate(Quantity::of('999999999999.999999'), FinancialAmount::of('987654321987.654321'), FinancialRoundingMode::HalfUp);

        $this->assertSame('987654321987654320012345.678012345679', $result->exactAmount);
        $this->assertSame('987654321987654320012345.678012', $result->amount->value());
        $this->assertStringNotContainsStringIgnoringCase('e', $result->exactAmount);
    }

    public function test_the_product_matches_integer_arithmetic_wherever_that_is_safe(): void
    {
        mt_srand(3030);

        for ($i = 0; $i < 2000; $i++) {
            // Both below 3·10^9, so their product fits a 64-bit integer.
            [$q, $u] = [mt_rand(1, 2_999_999_999), mt_rand(1, 2_999_999_999)];
            $product = $q * $u;

            $this->assertSame((string) $product, ProportionalAwardCalculator::multiply((string) $q, (string) $u), "{$q} × {$u}");

            foreach (FinancialRoundingMode::cases() as $mode) {
                $result = ProportionalAwardCalculator::calculate(Quantity::fromMillionths($q), FinancialAmount::fromMillionths($u), $mode);

                $this->assertSame((string) self::reference($product, $mode), $result->amount->toMillionths(), "{$q} × {$u}, {$mode->value}");
            }
        }
    }

    public function test_negative_factors_are_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        ProportionalAwardCalculator::calculate(Quantity::of('-1'), FinancialAmount::of('1'), FinancialRoundingMode::HalfUp);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function notWholeNumbers(): array
    {
        return ['a sign' => ['-1', '2'], 'a decimal point' => ['1.5', '2'], 'text' => ['12', 'ab'], 'a trailing newline' => ["12\n", '2'], 'empty' => ['', '2']];
    }

    #[DataProvider('notWholeNumbers')]
    public function test_only_whole_numbers_are_multiplied(string $a, string $b): void
    {
        $this->expectException(InvalidArgumentException::class);

        ProportionalAwardCalculator::multiply($a, $b);
    }

    /**
     * Rounding done independently, with integers: `$product` is in
     * millionths of a millionth.
     */
    private static function reference(int $product, FinancialRoundingMode $mode): int
    {
        $quotient = intdiv($product, 1_000_000);
        $remainder = $product % 1_000_000;

        return $quotient + match ($mode) {
            FinancialRoundingMode::TowardZero => 0,
            FinancialRoundingMode::AwayFromZero => $remainder > 0 ? 1 : 0,
            FinancialRoundingMode::HalfUp => $remainder >= 500_000 ? 1 : 0,
            FinancialRoundingMode::HalfEven => $remainder > 500_000 || ($remainder === 500_000 && $quotient % 2 === 1) ? 1 : 0,
        };
    }
}
