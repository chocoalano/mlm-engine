<?php

declare(strict_types=1);

namespace PandaBear\Mlm\Exceptions;

use DomainException;

final class InvalidLedgerAccount extends DomainException
{
    public static function key(string $key): self
    {
        return new self(sprintf(
            'A ledger account key is 1–100 lowercase letters, digits, ".", "-" or "_", starting with a letter or digit; "%s" given.',
            $key,
        ));
    }

    public static function reservedKey(string $key): self
    {
        return new self("A system account key cannot start with \"wallet.\", which names wallet accounts; \"{$key}\" given.");
    }

    /**
     * The key names an existing account that is not a system account — only
     * raw writes produce one.
     */
    public static function notASystemAccount(string $program, string $currency, string $key, string $account): self
    {
        return new self("Ledger account [{$account}] holds key \"{$key}\" in program [{$program}], {$currency}, but belongs to a wallet; a system account cannot claim it.");
    }

    public static function walletWithoutAccount(string $wallet): self
    {
        return new self("Wallet [{$wallet}] has no ledger account; a wallet is opened together with its account, so only raw writes produce this.");
    }
}
