<?php

declare(strict_types=1);

namespace PandaBear\Mlm\Tests;

use PandaBear\Mlm\Exceptions\InvalidVolumeEntry;
use PandaBear\Mlm\Volume\Quantity;
use PHPUnit\Framework\Attributes\DataProvider;

final class QuantityTest extends TestCase
{
    /**
     * @return array<string, array{int|string, string}>
     */
    public static function accepted(): array
    {
        return [
            'integer' => [25, '25'],
            'integer-like string' => ['25', '25'],
            'one decimal' => ['25.5', '25.5'],
            'full scale' => ['0.000001', '0.000001'],
            'trailing zeros are not precision' => ['1.1000000', '1.1'],
            'leading zeros' => ['0007.50', '7.5'],
            'negative' => ['-0.125', '-0.125'],
            'negative zero' => ['-0.000', '0'],
            'the largest single entry' => ['999999999999.999999', '999999999999.999999'],
            'larger than any entry' => ['1000000000000', '1000000000000'],
            'far beyond a 64-bit integer' => ['123456789012345678901234.5', '123456789012345678901234.5'],
        ];
    }

    #[DataProvider('accepted')]
    public function test_it_accepts_exact_decimals_and_keeps_one_canonical_form(int|string $input, string $canonical): void
    {
        $this->assertSame($canonical, Quantity::of($input)->value());
        $this->assertSame($canonical, (string) Quantity::of($input));
    }

    /**
     * @return array<string, array{mixed, string}>
     */
    public static function refused(): array
    {
        return [
            'a float' => [1.1, 'float'],
            'an integral float' => [2.0, 'float'],
            'null' => [null, 'decimal string or an integer'],
            'a boolean' => [true, 'decimal string or an integer'],
            'empty' => ['', 'plain decimal'],
            'surrounding space' => [' 1', 'plain decimal'],
            'a trailing dot' => ['1.', 'plain decimal'],
            'a leading dot' => ['.5', 'plain decimal'],
            'a plus sign' => ['+1', 'plain decimal'],
            'an exponent' => ['1e3', 'plain decimal'],
            'a decimal comma' => ['1,5', 'plain decimal'],
            'not a number' => ['abc', 'plain decimal'],
            'seven decimal places' => ['1.1234567', 'not rounded'],
        ];
    }

    #[DataProvider('refused')]
    public function test_it_refuses_anything_that_is_not_an_exact_decimal_in_range(mixed $input, string $reason): void
    {
        $this->expectException(InvalidVolumeEntry::class);
        $this->expectExceptionMessage($reason);

        Quantity::of($input);
    }

    public function test_it_converts_to_millionths_as_an_exact_integer_string(): void
    {
        $this->assertSame('25500000', Quantity::of('25.5')->toMillionths());
        $this->assertSame('-125000', Quantity::of('-0.125')->toMillionths());
        $this->assertSame('1', Quantity::of('0.000001')->toMillionths());
        $this->assertSame('0', Quantity::of('0')->toMillionths());
        $this->assertSame('999999999999999999', Quantity::of('999999999999.999999')->toMillionths());
        $this->assertSame('123456789012345678901234500000', Quantity::of('123456789012345678901234.5')->toMillionths());
    }

    public function test_it_reads_millionths_exactly(): void
    {
        $this->assertSame('25.5', Quantity::fromMillionths(25_500_000)->value());
        $this->assertSame('25.5', Quantity::fromMillionths('25500000')->value());
        $this->assertSame('-0.000001', Quantity::fromMillionths(-1)->value());
        $this->assertSame('0', Quantity::fromMillionths(0)->value());
        $this->assertSame('0', Quantity::fromMillionths('-0')->value());
    }

    /**
     * @return array<string, array{string}>
     */
    public static function millionths(): array
    {
        return [
            'zero' => ['0'],
            'one millionth' => ['1'],
            'a normal entry' => ['25500000'],
            'the largest single entry' => ['999999999999999999'],
            'beyond a 64-bit integer' => ['123456789012345678901234'],
            'negative beyond a 64-bit integer' => ['-123456789012345678901234'],
        ];
    }

    #[DataProvider('millionths')]
    public function test_millionths_round_trip_exactly_at_any_size(string $millionths): void
    {
        $quantity = Quantity::fromMillionths($millionths);

        $this->assertSame($millionths, $quantity->toMillionths());
        $this->assertTrue(Quantity::of($quantity->value())->equals($quantity));
    }

    public function test_a_sum_larger_than_an_integer_still_reads_exactly(): void
    {
        $this->assertSame('123456789012345678.901234', Quantity::fromMillionths('123456789012345678901234')->value());
    }

    public function test_it_negates_and_compares(): void
    {
        $this->assertSame('-25.5', Quantity::of('25.5')->negate()->value());
        $this->assertSame('25.5', Quantity::of('-25.5')->negate()->value());
        $this->assertTrue(Quantity::of('0')->negate()->isZero());
        $this->assertTrue(Quantity::of('0.000001')->isPositive());
        $this->assertTrue(Quantity::of('-0.000001')->isNegative());
        $this->assertTrue(Quantity::of('2.50')->equals(Quantity::of('2.5')));
    }
}
