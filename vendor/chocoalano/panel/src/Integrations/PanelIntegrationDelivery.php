<?php

declare(strict_types=1);

namespace PandaPanel\Integrations;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Date;

/**
 * One attempt, kept so somebody can see what happened.
 *
 * This table is the reason the feature shipped without history the first time:
 * a row per write to a busy resource is a table that outgrows the records it
 * describes, and "remember to prune it" is not a design. So it prunes itself,
 * twice over and without anything being scheduled:
 *
 * - **A hard cap per integration.** Only the most recent
 *   `keep_per_integration` rows survive. This is the one that makes the
 *   guarantee true: it holds even in an application that never runs a
 *   scheduler, and it bounds the table at cap × integrations regardless of
 *   traffic.
 * - **A retention window.** Anything older than `retention_days` goes, so a
 *   handful of integrations that fire twice a year do not keep rows from three
 *   years ago.
 *
 * Both run in one statement after each recorded delivery — see `record()` —
 * which is the moment the table is known to have grown by exactly one.
 *
 * @property int $id
 * @property int $integration_id
 * @property Trigger $trigger
 * @property string $method
 * @property string $url
 * @property string $delivery_id
 * @property int|null $status
 * @property int|null $duration_ms
 * @property string|null $error
 * @property string|null $request_body
 * @property string|null $response_body
 * @property Carbon $attempted_at
 */
final class PanelIntegrationDelivery extends Model
{
    /** How much of a body is worth keeping to understand a failure. */
    public const BODY_LIMIT = 2000;

    public $timestamps = false;

    protected $table = 'panel_integration_deliveries';

    /** @var list<string> */
    protected $fillable = [
        'integration_id', 'trigger', 'method', 'url', 'delivery_id',
        'status', 'duration_ms', 'error', 'request_body', 'response_body',
        'attempted_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'trigger' => Trigger::class,
            'attempted_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<PanelIntegration, $this>
     */
    public function integration(): BelongsTo
    {
        return $this->belongsTo(PanelIntegration::class, 'integration_id');
    }

    /**
     * Whether history is kept at all.
     *
     * An application that wants none says so, and then nothing is written and
     * there is nothing to prune.
     */
    public static function enabled(): bool
    {
        return Config::get('panda-panel.integrations.history.enabled', true) === true;
    }

    /**
     * Trims one integration's history back inside both bounds.
     *
     * Ids rather than dates for the cap, because they are monotonic and a
     * `DELETE ... WHERE id <= ?` is portable in a way that deleting against a
     * subquery on the same table is not.
     */
    public static function prune(int $integrationId): void
    {
        $keep = max(1, (int) Config::get('panda-panel.integrations.history.keep_per_integration', 50));
        $days = max(0, (int) Config::get('panda-panel.integrations.history.retention_days', 30));

        // The id of the newest row that is already past the cap. Everything at
        // or below it is surplus.
        $cutoffId = self::query()
            ->where('integration_id', $integrationId)
            ->orderByDesc('id')
            ->skip($keep)
            ->take(1)
            ->value('id');

        $query = self::query()->where('integration_id', $integrationId);

        if ($cutoffId === null && $days === 0) {
            return;
        }

        $query->where(function ($inner) use ($cutoffId, $days): void {
            if ($cutoffId !== null) {
                $inner->orWhere('id', '<=', $cutoffId);
            }

            if ($days > 0) {
                $inner->orWhere('attempted_at', '<', Date::now()->subDays($days));
            }
        });

        $query->delete();
    }
}
