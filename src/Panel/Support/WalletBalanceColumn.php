<?php

declare(strict_types=1);

namespace PandaBear\Mlm\Panel\Support;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use PandaBear\Mlm\Finance\FinancialAmount;
use PandaBear\Mlm\Finance\LedgerBalanceReader;
use PandaBear\Mlm\Models\Wallet;
use PandaPanel\Tables\Columns\Column;
use PandaPanel\Tables\Enums\ColumnType;
use PandaPanel\Tables\Filters\Constraints\Constraint;

/**
 * A wallet's exact balance, read from the ledger.
 *
 * Never stored: `LedgerBalanceReader::forWallets()` sums the page's postings
 * once, after the page's wallets are read, instead of once per row. Derived,
 * so it cannot be sorted, searched, or offered as a query condition.
 */
final class WalletBalanceColumn extends Column
{
    /** @var array<string, FinancialAmount> */
    private array $balances = [];

    public function type(): ColumnType
    {
        return ColumnType::Text;
    }

    /**
     * @param  Builder<covariant Model>  $query
     */
    public function applyQuery(Builder $query): void
    {
        $query->afterQuery(function (Collection $wallets): void {
            $this->balances = [...$this->balances, ...app(LedgerBalanceReader::class)->forWallets($wallets)];
        });
    }

    public function toQueryConstraint(): ?Constraint
    {
        return null;
    }

    public function toCell(Model $record): ?string
    {
        /** @var Wallet $record */
        $balance = $this->balances[(string) $record->getKey()] ??= app(LedgerBalanceReader::class)->forWallet($record);

        return Display::money($balance, $record->currency);
    }

    /**
     * @return array<string, mixed>
     */
    protected function extraArray(): array
    {
        return ['wrap' => false];
    }
}
