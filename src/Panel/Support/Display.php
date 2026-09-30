<?php

declare(strict_types=1);

namespace PandaBear\Mlm\Panel\Support;

use BackedEnum;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use PandaBear\Mlm\Commission\CommissionAdjustmentOutcome;
use PandaBear\Mlm\Commission\CommissionStatus;
use PandaBear\Mlm\Finance\FinancialAmount;
use PandaBear\Mlm\Models\CommissionPeriod;
use PandaBear\Mlm\Payout\PayoutBatchStatus;
use PandaBear\Mlm\Payout\PayoutRequestStatus;
use PandaBear\Mlm\Period\CommissionPeriodStatus;
use PandaBear\Mlm\Planning\PlanVersionStatus;
use PandaPanel\Tables\Enums\BadgeColor;

/**
 * How MLM values read on screen: translated labels, exact money, status
 * badges. Presentation only — nothing here decides what a status allows.
 */
final class Display
{
    /**
     * The status groups a badge can show, with the enum each one reads and
     * the colour of each value.
     *
     * @var array<string, array{class-string<BackedEnum>, array<string, BadgeColor>}>
     */
    private const STATUSES = [
        'period' => [CommissionPeriodStatus::class, [
            'open' => BadgeColor::Info,
            'open_input_closed' => BadgeColor::Warning,
            'calculated' => BadgeColor::Warning,
            'finalized' => BadgeColor::Neutral,
            'released' => BadgeColor::Success,
        ]],
        'commission' => [CommissionStatus::class, [
            'calculated' => BadgeColor::Neutral,
            'pending' => BadgeColor::Warning,
            'approved' => BadgeColor::Info,
            'held' => BadgeColor::Info,
            'available' => BadgeColor::Success,
            'posted' => BadgeColor::Success,
            'cancelled' => BadgeColor::Neutral,
            'reversed' => BadgeColor::Danger,
        ]],
        'payout' => [PayoutRequestStatus::class, [
            'requested' => BadgeColor::Warning,
            'approved' => BadgeColor::Info,
            'processing' => BadgeColor::Info,
            'settled' => BadgeColor::Success,
            'failed' => BadgeColor::Danger,
            'cancelled' => BadgeColor::Neutral,
        ]],
        'batch' => [PayoutBatchStatus::class, [
            'open' => BadgeColor::Neutral,
            'sealed' => BadgeColor::Info,
            'processing' => BadgeColor::Warning,
            'completed' => BadgeColor::Success,
            'cancelled' => BadgeColor::Neutral,
        ]],
        'plan_version' => [PlanVersionStatus::class, [
            'draft' => BadgeColor::Neutral,
            'validated' => BadgeColor::Info,
            'published' => BadgeColor::Info,
            'active' => BadgeColor::Success,
            'superseded' => BadgeColor::Neutral,
            'archived' => BadgeColor::Neutral,
        ]],
        'adjustment' => [CommissionAdjustmentOutcome::class, [
            'recorded' => BadgeColor::Info,
            'adjusted' => BadgeColor::Warning,
            'cancelled' => BadgeColor::Danger,
            'reversed' => BadgeColor::Danger,
            'already_cancelled' => BadgeColor::Neutral,
            'already_reversed' => BadgeColor::Neutral,
        ]],
    ];

    public static function field(string $key): string
    {
        return __('mlm::mlm.fields.'.$key);
    }

    public static function section(string $key): string
    {
        return __('mlm::mlm.sections.'.$key);
    }

    public static function none(): string
    {
        return __('mlm::mlm.values.none');
    }

    /**
     * An exact amount with its currency. Never a float: the canonical decimal
     * string the ledger itself keeps.
     */
    public static function money(FinancialAmount|int|string|null $amount, ?string $currency): ?string
    {
        if ($amount === null) {
            return null;
        }

        $amount = $amount instanceof FinancialAmount ? $amount : FinancialAmount::fromMillionths($amount);

        return trim($amount->value().' '.($currency ?? ''));
    }

    public static function moment(DateTimeInterface|string|null $at): ?string
    {
        if ($at === null || $at === '') {
            return null;
        }

        return CarbonImmutable::parse($at)->format('Y-m-d H:i:s');
    }

    /**
     * @return array<string, string> every value of the group, labelled
     */
    public static function statusLabels(string $group): array
    {
        $labels = [];

        foreach (array_keys(self::STATUSES[$group][1]) as $value) {
            $labels[$value] = self::status($group, $value);
        }

        return $labels;
    }

    /**
     * @return array<string, string> the values an enum really has — the
     *                               display-only ones left out — for a filter
     */
    public static function statusOptions(string $group): array
    {
        $options = [];

        foreach (self::STATUSES[$group][0]::cases() as $case) {
            $options[(string) $case->value] = self::status($group, $case);
        }

        return $options;
    }

    /**
     * @return array<string, BadgeColor>
     */
    public static function statusColors(string $group): array
    {
        return self::STATUSES[$group][1];
    }

    /**
     * The same colours, keyed by translated label — what an infolist badge
     * matches on.
     *
     * @return array<string, BadgeColor>
     */
    public static function statusColorsByLabel(string $group): array
    {
        $colors = [];

        foreach (self::STATUSES[$group][1] as $value => $color) {
            $colors[self::status($group, $value)] = $color;
        }

        return $colors;
    }

    public static function status(string $group, BackedEnum|string|null $status): ?string
    {
        if ($status === null) {
            return null;
        }

        $value = $status instanceof BackedEnum ? (string) $status->value : $status;

        return __("mlm::mlm.statuses.{$group}.{$value}");
    }

    public static function statusHelp(string $group, BackedEnum|string|null $status): ?string
    {
        if ($status === null) {
            return null;
        }

        $value = $status instanceof BackedEnum ? (string) $status->value : $status;

        return __("mlm::mlm.statuses.{$group}_help.{$value}");
    }

    /**
     * What a period's badge shows. An open period whose input is closed had
     * a calculation attempt that did not finish (ADR-029): its range is
     * frozen, and it must not read as an ordinary open period.
     */
    public static function periodState(CommissionPeriod $period): string
    {
        if ($period->status === CommissionPeriodStatus::Open && $period->input_closed_at !== null) {
            return 'open_input_closed';
        }

        return $period->status->value;
    }

    /**
     * Structured data, legibly: pretty JSON. Only ever displayed.
     */
    public static function json(mixed $value): ?string
    {
        if ($value === null || $value === []) {
            return null;
        }

        $encoded = json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return $encoded === false ? null : $encoded;
    }

    /**
     * An opaque reference shown conservatively in lists: its last four
     * characters, the rest masked.
     */
    public static function masked(?string $reference): ?string
    {
        if ($reference === null || $reference === '') {
            return null;
        }

        $length = mb_strlen($reference);

        return $length <= 4
            ? str_repeat('•', $length)
            : str_repeat('•', min(8, $length - 4)).mb_substr($reference, -4);
    }
}
