<?php

declare(strict_types=1);

namespace PandaBear\Mlm\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOneThrough;
use PandaBear\Mlm\Exceptions\InvalidMatrixPlacement;

/**
 * A generic placement edge in the matrix overlay (ADR-027): its member is
 * its parent's matrix child in numbered `slot`, from `assigned_at` on.
 *
 * Read-only through Eloquent. A position is only meaningful together with
 * the matrix paths it implies, so positions are written by
 * `MatrixPlacementManager` alone, and creating, updating or deleting one
 * through the model is refused. A slot never changes and a position is
 * never removed.
 *
 * @property string $id
 * @property string $matrix_network_id
 * @property string $placement_edge_id
 * @property string $parent_id
 * @property int $slot
 * @property CarbonImmutable $assigned_at
 * @property-read MatrixNetwork $network
 * @property-read PlacementEdge $placementEdge
 * @property-read Member $parent
 * @property-read Member|null $member
 */
final class MatrixPlacementPosition extends MlmModel
{
    protected $table = 'mlm_matrix_placement_positions';

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
            'slot' => 'integer',
            'assigned_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<MatrixNetwork, $this>
     */
    public function network(): BelongsTo
    {
        return $this->belongsTo(MatrixNetwork::class, 'matrix_network_id');
    }

    /**
     * The generic edge this position enrols.
     *
     * @return BelongsTo<PlacementEdge, $this>
     */
    public function placementEdge(): BelongsTo
    {
        return $this->belongsTo(PlacementEdge::class, 'placement_edge_id');
    }

    /**
     * The matrix parent: always the edge's placement parent.
     *
     * @return BelongsTo<Member, $this>
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(Member::class, 'parent_id');
    }

    /**
     * The placed member, through the edge: not stored twice.
     *
     * @return HasOneThrough<Member, PlacementEdge, $this>
     */
    public function member(): HasOneThrough
    {
        return $this->hasOneThrough(Member::class, PlacementEdge::class, 'id', 'id', 'placement_edge_id', 'member_id');
    }

    protected static function booted(): void
    {
        $refuse = static function (): never {
            throw InvalidMatrixPlacement::outsideManager();
        };

        self::creating($refuse);
        self::updating($refuse);
        self::deleting($refuse);
    }
}
