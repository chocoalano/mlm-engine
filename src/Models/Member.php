<?php

declare(strict_types=1);

namespace PandaBear\Mlm\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use PandaBear\Mlm\Database\Factories\MemberFactory;
use PandaBear\Mlm\Exceptions\InvalidExternalIdentity;

/**
 * A participant in one program. Not the application's user: the optional
 * external identity links a member to whatever the application calls its
 * people, without the package knowing the class.
 *
 * @property string $id
 * @property string $program_id
 * @property string $member_code
 * @property string|null $external_type
 * @property string|null $external_id
 * @property CarbonImmutable $joined_at
 * @property-read Program $program
 * @property-read Collection<int, Wallet> $wallets
 */
final class Member extends MlmModel
{
    /** @use HasFactory<MemberFactory> */
    use HasFactory;

    protected $table = 'mlm_members';

    /**
     * No `program_id`: a member joins a program through the relationship,
     * `$program->members()->create([...])`, never by naming one.
     */
    protected $fillable = [
        'member_code',
        'external_type',
        'external_id',
        'joined_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'joined_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<Program, $this>
     */
    public function program(): BelongsTo
    {
        return $this->belongsTo(Program::class);
    }

    /**
     * At most one per currency.
     *
     * @return HasMany<Wallet, $this>
     */
    public function wallets(): HasMany
    {
        return $this->hasMany(Wallet::class);
    }

    /**
     * Stored as a string, so an integer id and its string form are the same
     * identity on every database.
     *
     * @return Attribute<string|null, string|int|null>
     */
    protected function externalId(): Attribute
    {
        return Attribute::make(
            set: static fn (string|int|null $value): ?string => $value === null ? null : (string) $value,
        );
    }

    protected static function booted(): void
    {
        // The unique index cannot see a half-set identity — NULLs never
        // collide — so both halves are required together, or neither.
        self::saving(static function (Member $member): void {
            $type = $member->external_type;
            $id = $member->external_id;

            if ($type === null && $id === null) {
                return;
            }

            if ($type === null || $id === null || trim($type) === '' || trim($id) === '') {
                throw InvalidExternalIdentity::incomplete($type, $id);
            }
        });
    }

    protected static function newFactory(): MemberFactory
    {
        return MemberFactory::new();
    }
}
