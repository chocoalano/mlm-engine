<?php

declare(strict_types=1);

namespace PandaBear\Mlm\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use PandaBear\Mlm\Exceptions\InvalidMatrixNetwork;

/**
 * A program's matrix network (ADR-027): every matrix parent in the program
 * has numbered slots 1 to `width`. One per program; its width is structure,
 * not a commission setting, and never changes.
 *
 * Read-only through Eloquent: written once, by `MatrixNetworkManager`.
 *
 * @property string $id
 * @property string $program_id
 * @property int $width
 * @property-read Program $program
 */
final class MatrixNetwork extends MlmModel
{
    protected $table = 'mlm_matrix_networks';

    /**
     * @var list<string>
     */
    protected $guarded = ['*'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['width' => 'integer'];
    }

    /**
     * @return BelongsTo<Program, $this>
     */
    public function program(): BelongsTo
    {
        return $this->belongsTo(Program::class);
    }

    /**
     * @return HasMany<MatrixPlacementPosition, $this>
     */
    public function positions(): HasMany
    {
        return $this->hasMany(MatrixPlacementPosition::class);
    }

    protected static function booted(): void
    {
        $refuse = static function (): never {
            throw InvalidMatrixNetwork::outsideManager();
        };

        self::creating($refuse);
        self::updating($refuse);
        self::deleting($refuse);
    }
}
