<?php

declare(strict_types=1);

namespace PandaPanel\Integrations;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * One configured request.
 *
 * @property int $id
 * @property string $panel
 * @property string $resource
 * @property string $name
 * @property Trigger $trigger
 * @property string $method
 * @property string $url
 * @property array<string, string>|null $headers
 * @property array<string, string>|null $query
 * @property string $body_type
 * @property string|null $body
 * @property bool $is_active
 * @property int|null $last_status
 * @property string|null $last_error
 * @property Carbon|null $last_attempted_at
 * @property string|null $secret
 */
final class PanelIntegration extends Model
{
    protected $table = 'panel_integrations';

    /**
     * Guarded rather than fillable, and every write goes through the
     * integrations page, which dehydrates from a declared schema — so the
     * request body cannot introduce a column the form never offered.
     *
     * @var list<string>
     */
    protected $fillable = [
        'panel', 'resource', 'name', 'trigger', 'method', 'url',
        'headers', 'query', 'body_type', 'body', 'is_active', 'secret',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'trigger' => Trigger::class,
            'headers' => 'array',
            'query' => 'array',
            'is_active' => 'boolean',
            'last_attempted_at' => 'datetime',
            // Encrypted at rest. It is the one value here that proves a
            // request came from this panel, so a database dump should not
            // hand somebody the ability to forge one.
            'secret' => 'encrypted',
        ];
    }

    /**
     * Every integration signs, and none of them had to be told to.
     *
     * Generated on create rather than offered as an option, because a webhook
     * a receiver cannot authenticate is the default nobody would have chosen
     * deliberately. It can be rotated from the screen; it cannot be absent.
     */
    protected static function booted(): void
    {
        self::creating(static function (self $integration): void {
            $integration->secret ??= IntegrationSignature::generate();
        });
    }

    /**
     * @return HasMany<PanelIntegrationDelivery, $this>
     */
    public function deliveries(): HasMany
    {
        return $this->hasMany(PanelIntegrationDelivery::class, 'integration_id')
            ->orderByDesc('id');
    }

    /**
     * The active integrations one write should fire.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeFiring(Builder $query, string $panel, string $resource, Trigger $trigger): Builder
    {
        return $query
            ->where('panel', $panel)
            ->where('resource', $resource)
            ->where('trigger', $trigger->value)
            ->where('is_active', true);
    }
}
