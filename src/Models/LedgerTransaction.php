<?php

declare(strict_types=1);

namespace PandaBear\Mlm\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use PandaBear\Mlm\Exceptions\ImmutableFinancialRecord;
use PandaBear\Mlm\Finance\LedgerRecorder;

/**
 * One balanced movement of value in one program and one currency: its
 * postings — one per account — sum to exactly zero. It names its business
 * source and the caller's idempotency key, and occurred at a business moment
 * the caller gave.
 *
 * Written by `LedgerRecorder` alone, together with its postings, and never
 * changed or deleted: a correction is a reversal — a second transaction with
 * every posting negated. Read-only through Eloquent. Raw query-builder writes
 * bypass this and are not a supported way to keep the ledger's invariants.
 *
 * @property string $id
 * @property string $program_id
 * @property string $currency
 * @property string $type
 * @property string $source_type
 * @property string $source_id
 * @property string $idempotency_key
 * @property CarbonImmutable $occurred_at
 * @property string|null $reversal_of_id
 * @property-read Program $program
 * @property-read Collection<int, LedgerPosting> $postings
 * @property-read LedgerTransaction|null $original
 * @property-read LedgerTransaction|null $reversal
 */
final class LedgerTransaction extends MlmModel
{
    protected $table = 'mlm_ledger_transactions';

    /**
     * @var list<string>
     */
    protected $guarded = ['*'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'occurred_at' => 'immutable_datetime',
        ];
    }

    public function isReversal(): bool
    {
        return $this->reversal_of_id !== null;
    }

    /**
     * @return BelongsTo<Program, $this>
     */
    public function program(): BelongsTo
    {
        return $this->belongsTo(Program::class);
    }

    /**
     * In account order: the transaction's canonical form.
     *
     * @return HasMany<LedgerPosting, $this>
     */
    public function postings(): HasMany
    {
        return $this->hasMany(LedgerPosting::class)->orderBy('ledger_account_id');
    }

    /**
     * The transaction this one reverses, when it is a reversal.
     *
     * @return BelongsTo<LedgerTransaction, $this>
     */
    public function original(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reversal_of_id');
    }

    /**
     * The transaction that reverses this one, once it has been reversed.
     *
     * @return HasOne<LedgerTransaction, $this>
     */
    public function reversal(): HasOne
    {
        return $this->hasOne(self::class, 'reversal_of_id');
    }

    protected static function booted(): void
    {
        $refuse = static function (): never {
            throw ImmutableFinancialRecord::outsideWriter(self::class, LedgerRecorder::class);
        };

        self::creating($refuse);
        self::updating($refuse);
        self::deleting($refuse);
    }
}
