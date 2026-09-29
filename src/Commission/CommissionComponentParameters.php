<?php

declare(strict_types=1);

namespace PandaBear\Mlm\Commission;

use PandaBear\Mlm\Exceptions\InvalidCurrencyCode;
use PandaBear\Mlm\Exceptions\InvalidPlanDefinition;
use PandaBear\Mlm\Finance\CurrencyCode;
use PandaBear\Mlm\Finance\FinanceInput;
use PandaBear\Mlm\Planning\DefinitionInput;

/**
 * @internal
 *
 * A `commission.strategy` component's parameters, read exactly: the four
 * fields below and nothing else.
 */
final readonly class CommissionComponentParameters
{
    public const FIELDS = ['currency', 'parameters', 'source_account', 'strategy'];

    /**
     * @param  array<string, mixed>  $parameters
     */
    private function __construct(
        public string $strategy,
        public CurrencyCode $currency,
        public string $sourceAccount,
        public array $parameters,
    ) {}

    /**
     * @param  array<string, mixed>  $parameters  the component's stored parameters
     *
     * @throws InvalidPlanDefinition
     */
    public static function parse(array $parameters): self
    {
        $fields = array_keys($parameters);
        sort($fields);
        $missing = array_values(array_diff(self::FIELDS, $fields));
        $unknown = array_values(array_diff($fields, self::FIELDS));

        if ($missing !== [] || $unknown !== []) {
            throw self::invalid(sprintf(
                'a commission component has exactly the parameters %s%s%s.',
                implode(', ', self::FIELDS),
                $missing === [] ? '' : '; missing: '.implode(', ', $missing),
                $unknown === [] ? '' : '; unknown: '.implode(', ', $unknown),
            ));
        }

        ['strategy' => $strategy, 'currency' => $currency, 'source_account' => $sourceAccount, 'parameters' => $own] = $parameters;

        if (! DefinitionInput::isIdentifier($strategy, DefinitionInput::DRIVER_LENGTH)) {
            throw self::invalid('"strategy" is a commission strategy key: 1–100 lowercase letters, digits, ".", "-" or "_", starting with a letter or digit.');
        }

        try {
            $currency = CurrencyCode::of(is_string($currency) ? $currency : '');
        } catch (InvalidCurrencyCode) {
            throw self::invalid('"currency" is three uppercase ASCII letters, such as "IDR".');
        }

        if (! is_string($sourceAccount)
            || ! FinanceInput::isIdentifier($sourceAccount, FinanceInput::ACCOUNT_KEY_LENGTH)
            || str_starts_with($sourceAccount, 'wallet.')) {
            throw self::invalid('"source_account" is the key of a system ledger account: 1–100 lowercase letters, digits, ".", "-" or "_", not a wallet account.');
        }

        if (! is_array($own) || ($own !== [] && array_is_list($own))) {
            throw self::invalid('"parameters" is the strategy\'s own JSON object.');
        }

        return new self($strategy, $currency, $sourceAccount, $own);
    }

    private static function invalid(string $reason): InvalidPlanDefinition
    {
        return InvalidPlanDefinition::input('commission component', $reason);
    }
}
