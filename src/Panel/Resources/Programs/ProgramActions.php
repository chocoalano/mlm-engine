<?php

declare(strict_types=1);

namespace PandaBear\Mlm\Panel\Resources\Programs;

use Illuminate\Database\Eloquent\Model;
use PandaBear\Mlm\Exceptions\ConflictingProgramRecord;
use PandaBear\Mlm\Exceptions\InvalidCurrencyCode;
use PandaBear\Mlm\Exceptions\InvalidLedgerAccount;
use PandaBear\Mlm\Exceptions\InvalidMatrixNetwork;
use PandaBear\Mlm\Finance\LedgerAccountManager;
use PandaBear\Mlm\Matrix\MatrixNetworkManager;
use PandaBear\Mlm\Models\MatrixNetwork;
use PandaBear\Mlm\Models\Program;
use PandaBear\Mlm\Panel\MlmPermission;
use PandaBear\Mlm\Panel\Pages\GenealogyExplorer;
use PandaBear\Mlm\Panel\Resources\Members\MemberResource;
use PandaBear\Mlm\Panel\Resources\Payouts\PayoutRequestResource;
use PandaBear\Mlm\Panel\Resources\Periods\CommissionPeriodResource;
use PandaBear\Mlm\Panel\Resources\Plans\PlanResource;
use PandaBear\Mlm\Panel\Resources\Wallets\WalletResource;
use PandaBear\Mlm\Panel\Support\Display;
use PandaBear\Mlm\Panel\Support\DomainAction;
use PandaBear\Mlm\Program\ProgramManager;
use PandaPanel\Actions\Action;
use PandaPanel\Forms\Components\NumberInput;
use PandaPanel\Forms\Components\TextInput;
use PandaPanel\Forms\FormSchema;
use PandaPanel\Resources\Resource;

/**
 * Creating a program, opening its system ledger accounts, and fixing its
 * matrix width — each through its domain service — and the ways from a
 * program to the screens that hold its records.
 */
final class ProgramActions
{
    public static function creation(): Action
    {
        return DomainAction::withForm('new-program', MlmPermission::PROGRAMS_OPERATE)
            ->icon('plus')
            ->schema(static fn (): FormSchema => FormSchema::make()->schema([
                TextInput::make('code')->label(Display::field('code'))->required()->maxLength(64),
                TextInput::make('name')->label(Display::field('name'))->required()->maxLength(255),
            ]))
            ->tableAction(static function (array $data): void {
                DomainAction::attempt('new-program', MlmPermission::PROGRAMS_OPERATE, [ConflictingProgramRecord::class], static fn (): Program => app(ProgramManager::class)->create(
                    (string) ($data['code'] ?? ''),
                    (string) ($data['name'] ?? ''),
                ));
            });
    }

    /**
     * A system account — a commission source or a payout settlement
     * account. Opening one that exists returns it.
     */
    public static function openSystemAccount(): Action
    {
        return DomainAction::withForm('open-system-account', MlmPermission::PROGRAMS_OPERATE)
            ->icon('plus')
            ->schema(static fn (): FormSchema => FormSchema::make()->schema([
                TextInput::make('currency')->label(Display::field('currency'))->required()->maxLength(3),
                TextInput::make('key')->label(Display::field('account_key'))->helperText(__('mlm::mlm.helpers.account_key'))->required()->maxLength(100),
            ]))
            ->action(static function (Program $program, array $data = []): void {
                DomainAction::attempt('open-system-account', MlmPermission::PROGRAMS_OPERATE, [InvalidLedgerAccount::class, InvalidCurrencyCode::class], static fn () => app(LedgerAccountManager::class)->openSystemAccount(
                    $program,
                    (string) ($data['currency'] ?? ''),
                    (string) ($data['key'] ?? ''),
                ));
            });
    }

    /**
     * The program's one matrix network. Its width is fixed once set.
     */
    public static function configureMatrix(): Action
    {
        return DomainAction::withForm('configure-matrix', MlmPermission::NETWORK_OPERATE)
            ->icon('settings')
            ->visible(static fn (?Model $program = null): bool => $program instanceof Program
                && ! MatrixNetwork::query()->where('program_id', $program->getKey())->exists())
            ->schema(static fn (): FormSchema => FormSchema::make()->schema([
                NumberInput::make('width')->label(Display::field('width'))->integer()->min(1)->max(100)->required()->rules(['integer', 'between:1,100']),
            ]))
            ->action(static function (Program $program, array $data = []): void {
                DomainAction::attempt('configure-matrix', MlmPermission::NETWORK_OPERATE, [InvalidMatrixNetwork::class], static fn () => app(MatrixNetworkManager::class)->configure(
                    $program,
                    DomainAction::integer($data['width'] ?? null),
                ));
            });
    }

    /**
     * @return list<Action>
     */
    public static function links(): array
    {
        $filtered = static fn (string $resource): \Closure => static fn (Program $program): string => $resource::url().'?'.http_build_query(['filters' => ['program_id' => $program->getKey()]]);
        $open = static fn (string $resource): \Closure => static fn (): bool => $resource::canViewAny();

        /** @var array<string, class-string<resource>> $screens */
        $screens = [
            'members' => MemberResource::class,
            'plans' => PlanResource::class,
            'periods' => CommissionPeriodResource::class,
            'wallets' => WalletResource::class,
            'payouts' => PayoutRequestResource::class,
        ];

        $links = [];

        foreach ($screens as $key => $resource) {
            $links[] = DomainAction::link("open-{$key}", __("mlm::mlm.links.{$key}"), $filtered($resource), $open($resource));
        }

        $links[] = DomainAction::link(
            'open-genealogy',
            __('mlm::mlm.links.genealogy'),
            static fn (Program $program): string => GenealogyExplorer::url().'?'.http_build_query(['filters' => ['program_id' => $program->getKey()]]),
            static fn (): bool => GenealogyExplorer::canAccess(),
        );

        return $links;
    }
}
