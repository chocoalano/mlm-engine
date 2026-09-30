<?php

declare(strict_types=1);

namespace PandaBear\Mlm\Panel\Resources\Wallets;

use Illuminate\Database\Eloquent\Model;
use PandaBear\Mlm\Models\LedgerPosting;
use PandaBear\Mlm\Models\Wallet;
use PandaBear\Mlm\Panel\MlmPermission;
use PandaBear\Mlm\Panel\Support\Display;
use PandaBear\Mlm\Panel\Support\MlmRelationManager;
use PandaPanel\Tables\Columns\DateTimeColumn;
use PandaPanel\Tables\Columns\TextColumn;
use PandaPanel\Tables\Enums\SortDirection;
use PandaPanel\Tables\TableSchema;

/**
 * The wallet's ledger history, newest first and paginated. Immutable: a
 * correction is a new transaction, never an edit.
 */
final class WalletPostingsRelation extends MlmRelationManager
{
    protected static string $relationship = 'ledgerPostings';

    protected static ?string $key = 'ledger-postings';

    /** @var list<string> */
    protected static array $with = ['transaction'];

    protected static function viewPermission(): string
    {
        return MlmPermission::LEDGER_VIEW;
    }

    protected static function titleKey(): string
    {
        return 'postings';
    }

    public static function table(TableSchema $table, Model $owner): TableSchema
    {
        /** @var Wallet $owner */
        return $table
            ->columns([
                DateTimeColumn::make('occurred_at')
                    ->label(Display::field('occurred_at'))
                    ->formatUsing(static fn (mixed $value, LedgerPosting $posting): mixed => $posting->transaction->occurred_at),
                TextColumn::make('transaction.type')->label(Display::field('type')),
                TextColumn::make('source')
                    ->label(Display::field('source'))
                    ->formatUsing(static fn (mixed $value, LedgerPosting $posting): string => "{$posting->transaction->source_type}:{$posting->transaction->source_id}")
                    ->queryable(false),
                TextColumn::make('amount_millionths')
                    ->label(Display::field('signed_amount'))
                    ->formatUsing(static fn (mixed $value, LedgerPosting $posting): ?string => Display::money((string) $posting->amount_millionths, $owner->currency))
                    ->queryable(false),
                TextColumn::make('ledger_transaction_id')->label(Display::field('transaction')),
                DateTimeColumn::make('created_at')
                    ->label(Display::field('created_at'))
                    ->sortable(true, 'mlm_ledger_postings.created_at')
                    ->visible(false),
            ])
            ->defaultSort('created_at', SortDirection::Descending);
    }
}
