<?php

declare(strict_types=1);

namespace PandaBear\Mlm\Panel\Support;

use BackedEnum;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use PandaBear\Mlm\Binary\BinarySide;
use PandaBear\Mlm\Commission\CommissionStrategyRegistry;
use PandaBear\Mlm\Finance\FinancialRoundingMode;
use PandaBear\Mlm\Finance\LedgerBalanceReader;
use PandaBear\Mlm\Metrics\MetricRegistry;
use PandaBear\Mlm\Models\LedgerAccount;
use PandaBear\Mlm\Models\Member;
use PandaBear\Mlm\Models\PayoutBatch;
use PandaBear\Mlm\Models\PayoutBatchItem;
use PandaBear\Mlm\Models\PayoutRequest;
use PandaBear\Mlm\Models\Plan;
use PandaBear\Mlm\Models\PlanVersion;
use PandaBear\Mlm\Models\Program;
use PandaBear\Mlm\Models\Wallet;
use PandaBear\Mlm\Payout\PayoutRequestStatus;
use PandaBear\Mlm\Planning\PlanComponentDriverRegistry;
use PandaBear\Mlm\Planning\PlanVersionStatus;
use PandaBear\Mlm\Planning\Rules\RuleCombinator;
use PandaBear\Mlm\Planning\Rules\RuleOperator;

/**
 * The choices a form offers, read from the domain: registries for drivers,
 * strategies and metrics, enums for operators and sides, and bounded,
 * set-based queries for records. A choice offered here is a convenience —
 * the service a form calls still decides whether what was chosen is valid.
 */
final class Options
{
    private const LIMIT = 50;

    /**
     * The `existsIn()` table of a model — on the MLM connection — so a
     * record select accepts any real row rather than only the page of
     * options it happened to show. Whether that row may be used is still
     * the service's answer.
     *
     * @param  class-string<Model>  $model
     * @return array{string, string}
     */
    public static function exists(string $model): array
    {
        $instance = new $model;
        $connection = $instance->getConnectionName();

        return [($connection === null ? '' : $connection.'.').$instance->getTable(), $instance->getKeyName()];
    }

    /**
     * @return array<string, string>
     */
    public static function programs(?string $search = null): array
    {
        return Program::query()
            ->when(self::filled($search), static fn (Builder $query): Builder => $query->where(static fn (Builder $query): Builder => $query
                ->where('code', 'like', self::like($search))
                ->orWhere('name', 'like', self::like($search))))
            ->orderBy('code')
            ->limit(self::LIMIT)
            ->get(['id', 'code', 'name'])
            ->mapWithKeys(static fn (Program $program): array => [(string) $program->id => "{$program->code} · {$program->name}"])
            ->all();
    }

    /**
     * A program's members, by code, never more than a page of them.
     *
     * @return array<string, string>
     */
    public static function members(mixed $programId, ?string $search = null, ?string $except = null): array
    {
        if (! is_string($programId) || $programId === '') {
            return [];
        }

        return Member::query()
            ->where('program_id', $programId)
            ->when($except !== null, static fn (Builder $query): Builder => $query->whereKeyNot($except))
            ->when(self::filled($search), static fn (Builder $query): Builder => $query->where('member_code', 'like', self::like($search)))
            ->orderBy('member_code')
            ->limit(self::LIMIT)
            ->pluck('member_code', 'id')
            ->all();
    }

    /**
     * Members of every program, labelled with their program, for a form
     * that starts from the member.
     *
     * @return array<string, string>
     */
    public static function anyMembers(?string $search = null): array
    {
        return Member::query()
            ->with('program:id,code')
            ->when(self::filled($search), static fn (Builder $query): Builder => $query->where('member_code', 'like', self::like($search)))
            ->orderBy('member_code')
            ->limit(self::LIMIT)
            ->get(['id', 'program_id', 'member_code'])
            ->mapWithKeys(static fn (Member $member): array => [(string) $member->id => "{$member->member_code} · {$member->program->code}"])
            ->all();
    }

    /**
     * A member's wallets, each labelled with its exact ledger balance — the
     * context a payout request is made in, never a limit on it.
     *
     * @return array<string, string>
     */
    public static function walletsOf(mixed $memberId): array
    {
        if (! is_string($memberId) || $memberId === '') {
            return [];
        }

        $wallets = Wallet::query()->where('member_id', $memberId)->orderBy('currency')->get();
        $balances = app(LedgerBalanceReader::class)->forWallets($wallets);

        return $wallets
            ->mapWithKeys(static fn (Wallet $wallet): array => [(string) $wallet->id => __('mlm::mlm.values.wallet_option', [
                'currency' => $wallet->currency,
                'balance' => Display::money($balances[(string) $wallet->id], $wallet->currency),
            ])])
            ->all();
    }

    /**
     * A program's system ledger accounts — never a wallet's — optionally in
     * one currency.
     *
     * @return array<string, string>
     */
    public static function systemAccounts(mixed $programId, ?string $currency = null, string $value = 'id'): array
    {
        if (! is_string($programId) || $programId === '') {
            return [];
        }

        return LedgerAccount::query()
            ->where('program_id', $programId)
            ->whereNull('wallet_id')
            ->when($currency !== null, static fn (Builder $query): Builder => $query->where('currency', $currency))
            ->orderBy('key')
            ->orderBy('currency')
            ->limit(self::LIMIT)
            ->get(['id', 'key', 'currency'])
            ->mapWithKeys(static fn (LedgerAccount $account): array => [(string) $account->{$value} => "{$account->key} · {$account->currency}"])
            ->all();
    }

    /**
     * The system accounts a payout from this wallet may settle through: the
     * wallet's program and currency.
     *
     * @return array<string, string>
     */
    public static function settlementAccountsFor(mixed $walletId): array
    {
        if (! is_string($walletId) || $walletId === '') {
            return [];
        }

        $wallet = Wallet::query()->find($walletId);

        return $wallet === null ? [] : self::systemAccounts((string) $wallet->program_id, $wallet->currency);
    }

    /**
     * Approved requests of the batch's program and currency that no batch
     * holds yet.
     *
     * @return array<string, string>
     */
    public static function batchCandidates(PayoutBatch $batch): array
    {
        return PayoutRequest::query()
            ->with('member:id,member_code')
            ->where('program_id', $batch->program_id)
            ->where('currency', $batch->currency)
            ->where('status', PayoutRequestStatus::Approved->value)
            ->whereNotIn('id', PayoutBatchItem::query()->select('payout_request_id'))
            ->orderBy('approved_at')
            ->limit(self::LIMIT)
            ->get(['id', 'member_id', 'amount_millionths', 'currency'])
            ->mapWithKeys(static fn (PayoutRequest $request): array => [(string) $request->id => $request->member->member_code.' · '.Display::money($request->amount_millionths, $request->currency)])
            ->all();
    }

    /**
     * The active versions of a program's plans — the only ones a period can
     * be opened on.
     *
     * @return array<string, string>
     */
    public static function activeVersions(mixed $programId): array
    {
        if (! is_string($programId) || $programId === '') {
            return [];
        }

        return PlanVersion::query()
            ->with('plan:id,code,name')
            ->where('status', PlanVersionStatus::Active->value)
            ->whereIn('plan_id', Plan::query()->select('id')->where('program_id', $programId))
            ->get(['id', 'plan_id', 'version'])
            ->mapWithKeys(static fn (PlanVersion $version): array => [(string) $version->id => $version->plan->name.' '.__('mlm::mlm.values.version', ['version' => $version->version])])
            ->all();
    }

    /**
     * @return array<string, string>
     */
    public static function versionsOf(Plan $plan): array
    {
        return $plan->versions()
            ->orderByDesc('version')
            ->get(['id', 'version', 'status'])
            ->mapWithKeys(static fn (PlanVersion $version): array => [(string) $version->id => __('mlm::mlm.values.version', ['version' => $version->version]).' · '.Display::status('plan_version', $version->status)])
            ->all();
    }

    /**
     * @return array<string, string>
     */
    public static function drivers(): array
    {
        return self::keyed(app(PlanComponentDriverRegistry::class)->keys());
    }

    /**
     * @return array<string, string>
     */
    public static function strategies(): array
    {
        return self::keyed(app(CommissionStrategyRegistry::class)->keys());
    }

    /**
     * @return array<string, string>
     */
    public static function metrics(): array
    {
        return self::keyed(app(MetricRegistry::class)->keys());
    }

    /**
     * @return array<string, string>
     */
    public static function operators(): array
    {
        return self::cases(RuleOperator::cases());
    }

    /**
     * @return array<string, string>
     */
    public static function combinators(): array
    {
        return array_map(static fn (string $value): string => __("mlm::mlm.values.match_{$value}"), self::cases(RuleCombinator::cases()));
    }

    /**
     * @return array<string, string>
     */
    public static function roundingModes(): array
    {
        return self::cases(FinancialRoundingMode::cases());
    }

    /**
     * @return array<string, string>
     */
    public static function binarySides(): array
    {
        return array_map(static fn (string $value): string => __("mlm::mlm.values.side_{$value}"), self::cases(BinarySide::cases()));
    }

    /**
     * @param  list<string>  $keys
     * @return array<string, string>
     */
    private static function keyed(array $keys): array
    {
        sort($keys);

        return array_combine($keys, $keys);
    }

    /**
     * @param  list<BackedEnum>  $cases
     * @return array<string, string>
     */
    private static function cases(array $cases): array
    {
        $options = [];

        foreach ($cases as $case) {
            $options[(string) $case->value] = (string) $case->value;
        }

        return $options;
    }

    private static function filled(?string $search): bool
    {
        return $search !== null && trim($search) !== '';
    }

    private static function like(?string $search): string
    {
        return '%'.str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], trim((string) $search)).'%';
    }
}
