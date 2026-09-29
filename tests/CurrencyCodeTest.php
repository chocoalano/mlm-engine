<?php

declare(strict_types=1);

namespace PandaBear\Mlm\Tests;

use PandaBear\Mlm\Exceptions\InvalidCurrencyCode;
use PandaBear\Mlm\Finance\CurrencyCode;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase as PHPUnitTestCase;

/**
 * A currency code's shape, and nothing more: no list of currencies, no
 * rewriting.
 */
final class CurrencyCodeTest extends PHPUnitTestCase
{
    /**
     * @return array<string, array{string}>
     */
    public static function accepted(): array
    {
        return ['IDR' => ['IDR'], 'USD' => ['USD'], 'EUR' => ['EUR'], 'JPY' => ['JPY'], 'not a real currency, but the right shape' => ['XYZ']];
    }

    #[DataProvider('accepted')]
    public function test_three_uppercase_letters_are_a_currency_code(string $code): void
    {
        $this->assertSame($code, CurrencyCode::of($code)->value());
        $this->assertSame($code, (string) CurrencyCode::of($code));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function refused(): array
    {
        return [
            'lowercase' => ['idr'],
            'mixed case' => ['Usd'],
            'two letters' => ['US'],
            'four letters' => ['USDD'],
            'digits' => ['123'],
            'leading space' => [' IDR'],
            'trailing space' => ['IDR '],
            'a trailing newline' => ["IDR\n"],
            'non-ASCII letters' => ['ÀBC'],
            'empty' => [''],
        ];
    }

    #[DataProvider('refused')]
    public function test_anything_else_is_refused_not_rewritten(string $code): void
    {
        $this->expectException(InvalidCurrencyCode::class);
        $this->expectExceptionMessage('three uppercase ASCII letters');

        CurrencyCode::of($code);
    }

    public function test_a_code_or_its_text_is_accepted_where_a_currency_is_expected(): void
    {
        $idr = CurrencyCode::of('IDR');

        $this->assertSame($idr, CurrencyCode::from($idr));
        $this->assertTrue(CurrencyCode::from('IDR')->equals($idr));
        $this->assertFalse(CurrencyCode::from('USD')->equals($idr));
    }
}
