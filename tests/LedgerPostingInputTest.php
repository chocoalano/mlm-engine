<?php

declare(strict_types=1);

namespace PandaBear\Mlm\Tests;

use PandaBear\Mlm\Exceptions\InvalidFinancialAmount;
use PandaBear\Mlm\Exceptions\InvalidLedgerTransaction;
use PandaBear\Mlm\Finance\FinancialAmount;
use PandaBear\Mlm\Finance\LedgerPostingInput;
use PandaBear\Mlm\Models\LedgerAccount;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * One posting holds what the signed 64-bit column can store *and* reverse:
 * the same largest magnitude either way, never zero.
 */
final class LedgerPostingInputTest extends TestCase
{
    public function test_the_largest_posting_is_accepted_either_way(): void
    {
        $account = new LedgerAccount;

        $this->assertSame('9223372036854.775807', LedgerPostingInput::of($account, '9223372036854.775807')->amount->value());
        $this->assertSame('-9223372036854.775807', LedgerPostingInput::of($account, '-9223372036854.775807')->amount->value());
        $this->assertSame('9223372036854775807', LedgerPostingInput::of($account, '9223372036854.775807')->amount->toMillionths());
    }

    /**
     * @return array<string, array{string}>
     */
    public static function tooLarge(): array
    {
        return [
            'one millionth above' => ['9223372036854.775808'],
            'the signed minimum, which could not be reversed' => ['-9223372036854.775808'],
            'far above' => ['100000000000000'],
            'far below' => ['-100000000000000'],
        ];
    }

    #[DataProvider('tooLarge')]
    public function test_a_posting_beyond_the_reversible_range_is_refused(string $amount): void
    {
        $this->expectException(InvalidLedgerTransaction::class);
        $this->expectExceptionMessage('One posting holds at most 9223372036854.775807 either way');

        LedgerPostingInput::of(new LedgerAccount, $amount);
    }

    public function test_a_zero_posting_is_refused(): void
    {
        foreach (['0', '-0.000000', FinancialAmount::zero()] as $zero) {
            try {
                LedgerPostingInput::of(new LedgerAccount, $zero);
                $this->fail('A zero posting was accepted.');
            } catch (InvalidLedgerTransaction $exception) {
                $this->assertStringContainsString('A posting of zero records nothing', $exception->getMessage());
            }
        }
    }

    public function test_a_float_amount_is_refused(): void
    {
        $this->expectException(InvalidFinancialAmount::class);

        LedgerPostingInput::of(new LedgerAccount, FinancialAmount::of(0.1));
    }
}
