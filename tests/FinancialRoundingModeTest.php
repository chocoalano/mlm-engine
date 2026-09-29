<?php

declare(strict_types=1);

namespace PandaBear\Mlm\Tests;

use PandaBear\Mlm\Finance\FinancialRoundingMode;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase as PHPUnitTestCase;

/**
 * Four rounding modes, spelled exactly, with no default, no alias and no
 * normalisation.
 */
final class FinancialRoundingModeTest extends PHPUnitTestCase
{
    public function test_exactly_four_modes_exist(): void
    {
        $this->assertSame(
            ['toward_zero', 'away_from_zero', 'half_up', 'half_even'],
            array_map(static fn (FinancialRoundingMode $mode): string => $mode->value, FinancialRoundingMode::cases()),
        );

        foreach (FinancialRoundingMode::cases() as $mode) {
            $this->assertSame($mode, FinancialRoundingMode::parse($mode->value));
        }
    }

    /**
     * @return array<string, array{mixed}>
     */
    public static function refused(): array
    {
        return [
            'uppercase' => ['HALF_UP'],
            'a hyphen' => ['half-up'],
            'a space' => ['half up'],
            'a leading space' => [' half_up'],
            'a trailing newline' => ["half_up\n"],
            'an alias' => ['bankers'],
            'empty' => [''],
            'null' => [null],
            'a boolean' => [true],
            'an integer' => [1],
            'the enum name' => ['HalfUp'],
        ];
    }

    #[DataProvider('refused')]
    public function test_anything_else_is_not_a_mode(mixed $value): void
    {
        $this->assertNull(FinancialRoundingMode::parse($value));
    }

    /**
     * Remainders in millionths of a millionth; whether one more millionth
     * results, below and above an even and an odd last digit.
     *
     * @return array<string, array{FinancialRoundingMode, int, bool, bool}>
     */
    public static function increments(): array
    {
        return [
            'toward_zero never' => [FinancialRoundingMode::TowardZero, 999_999, true, false],
            'toward_zero at a half' => [FinancialRoundingMode::TowardZero, 500_000, true, false],
            'away_from_zero on the smallest remainder' => [FinancialRoundingMode::AwayFromZero, 1, false, true],
            'away_from_zero on none' => [FinancialRoundingMode::AwayFromZero, 0, true, false],
            'half_up just below' => [FinancialRoundingMode::HalfUp, 499_999, true, false],
            'half_up at a half' => [FinancialRoundingMode::HalfUp, 500_000, false, true],
            'half_even just below' => [FinancialRoundingMode::HalfEven, 499_999, true, false],
            'half_even just above' => [FinancialRoundingMode::HalfEven, 500_001, false, true],
            'half_even at a half, even' => [FinancialRoundingMode::HalfEven, 500_000, false, false],
            'half_even at a half, odd' => [FinancialRoundingMode::HalfEven, 500_000, true, true],
        ];
    }

    #[DataProvider('increments')]
    public function test_each_mode_decides_from_the_remainder(FinancialRoundingMode $mode, int $remainder, bool $odd, bool $increments): void
    {
        $this->assertSame($increments, $mode->increments($remainder, $odd));
    }
}
