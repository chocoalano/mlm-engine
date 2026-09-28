<?php

declare(strict_types=1);

namespace PandaBear\Mlm\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use PandaBear\Mlm\Database\Factories\ProgramFactory;

/**
 * The top-level business boundary: every member, and every domain built
 * later, belongs to exactly one program.
 *
 * @property string $id
 * @property string $code
 * @property string $name
 * @property-read Collection<int, Member> $members
 */
final class Program extends MlmModel
{
    /** @use HasFactory<ProgramFactory> */
    use HasFactory;

    protected $table = 'mlm_programs';

    protected $fillable = [
        'code',
        'name',
    ];

    /**
     * @return HasMany<Member, $this>
     */
    public function members(): HasMany
    {
        return $this->hasMany(Member::class);
    }

    protected static function newFactory(): ProgramFactory
    {
        return ProgramFactory::new();
    }
}
