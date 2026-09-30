<?php

declare(strict_types=1);

namespace PandaBear\Mlm\Panel\Resources\Wallets;

use Illuminate\Database\Eloquent\Model;
use PandaBear\Mlm\Finance\LedgerBalanceReader;
use PandaBear\Mlm\Models\Wallet;
use PandaBear\Mlm\Panel\MlmPermission;
use PandaBear\Mlm\Panel\Support\Display;
use PandaBear\Mlm\Panel\Support\MlmNavigation;
use PandaBear\Mlm\Panel\Support\MlmResource;
use PandaBear\Mlm\Panel\Support\WalletBalanceColumn;
use PandaPanel\Actions\ViewAction;
use PandaPanel\Infolists\Components\DateTimeEntry;
use PandaPanel\Infolists\Components\TextEntry;
use PandaPanel\Infolists\InfolistSchema;
use PandaPanel\Infolists\Layouts\Section;
use PandaPanel\Tables\Columns\DateTimeColumn;
use PandaPanel\Tables\Columns\TextColumn;
use PandaPanel\Tables\Enums\SortDirection;
use PandaPanel\Tables\TableSchema;

/**
 * Wallets, read-only. A balance is never stored: it is the exact sum of the
 * wallet account's ledger postings, read by `LedgerBalanceReader`.
 */
final class WalletResource extends MlmResource
{
    protected static string $model = Wallet::class;

    protected static ?string $slug = 'mlm-wallets';

    protected static ?string $navigationIcon = 'shield';

    protected static int $navigationSort = MlmNavigation::WALLETS;

    /** @var list<string> */
    protected static array $with = ['program', 'member', 'account'];

    protected static function viewPermission(): string
    {
        return MlmPermission::WALLETS_VIEW;
    }

    protected static function translationKey(): string
    {
        return 'wallet';
    }

    public static function recordTitle(Model $record): string
    {
        /** @var Wallet $record */
        return $record->member->member_code.' · '.$record->currency;
    }

    public static function table(TableSchema $table): TableSchema
    {
        return $table
            ->columns([
                TextColumn::make('program.name')->label(Display::field('program')),
                TextColumn::make('member.member_code')->label(Display::field('member'))->searchable(),
                TextColumn::make('currency')->label(Display::field('currency'))->sortable(),
                WalletBalanceColumn::make('balance')->label(Display::field('balance')),
                DateTimeColumn::make('created_at')->label(Display::field('created_at'))->sortable(),
            ])
            ->defaultSort('created_at', SortDirection::Descending)
            ->emptyState(self::emptyHeading(), self::emptyDescription(), 'shield')
            ->recordActions([ViewAction::make(self::class)]);
    }

    public static function infolist(InfolistSchema $schema): InfolistSchema
    {
        return $schema->schema([
            Section::make(Display::section('identity'))->columns(2)->schema([
                TextEntry::make('member.member_code')->label(Display::field('member')),
                TextEntry::make('program.name')->label(Display::field('program')),
                TextEntry::make('currency')->label(Display::field('currency')),
                TextEntry::make('balance')
                    ->label(Display::field('balance'))
                    ->formatUsing(static fn (mixed $value, Wallet $wallet): ?string => Display::money(app(LedgerBalanceReader::class)->forWallet($wallet), $wallet->currency)),
                TextEntry::make('account.key')
                    ->label(Display::field('ledger_account'))
                    ->visible(static fn (): bool => MlmPermission::allows(MlmPermission::LEDGER_VIEW)),
                TextEntry::make('id')->label(Display::field('id')),
                DateTimeEntry::make('created_at')->label(Display::field('created_at')),
            ]),
        ]);
    }

    public static function pages(): array
    {
        return [
            'index' => ListWallets::class,
            'view' => ViewWallet::class,
        ];
    }

    public static function relationManagers(): array
    {
        return [WalletPostingsRelation::class];
    }
}
