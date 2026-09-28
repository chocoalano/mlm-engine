<?php

declare(strict_types=1);

namespace PandaPanel\Integrations;

/**
 * The six moments an integration can fire on.
 *
 * They map one to one onto Eloquent's own model events, which is what makes
 * them universal: a record written by a resource form, by a table action, by
 * a bulk action, by an importer, or by a line of application code somewhere
 * else entirely all pass through the same six points. Hanging these off the
 * resource pages instead would have covered the form and nothing else — and
 * deletion, which has no page hooks at all, would have had none.
 */
enum Trigger: string
{
    case BeforeCreate = 'before_create';
    case AfterCreate = 'after_create';
    case BeforeUpdate = 'before_update';
    case AfterUpdate = 'after_update';
    case BeforeDelete = 'before_delete';
    case AfterDelete = 'after_delete';

    /** The Eloquent event this trigger listens to. */
    public function modelEvent(): string
    {
        return match ($this) {
            self::BeforeCreate => 'creating',
            self::AfterCreate => 'created',
            self::BeforeUpdate => 'updating',
            self::AfterUpdate => 'updated',
            self::BeforeDelete => 'deleting',
            self::AfterDelete => 'deleted',
        };
    }

    /**
     * Whether the record already exists in its final state when this fires.
     *
     * A `before` trigger sees the record as it is about to be written, which
     * is the whole reason to have one — and also why it cannot be queued: the
     * values it is describing are gone by the time a worker picks it up.
     */
    public function isAfter(): bool
    {
        return match ($this) {
            self::AfterCreate, self::AfterUpdate, self::AfterDelete => true,
            default => false,
        };
    }

    public function label(): string
    {
        return __('panda-panel::integrations.trigger.'.match ($this) {
            self::BeforeCreate => 'before_create',
            self::AfterCreate => 'after_create',
            self::BeforeUpdate => 'before_update',
            self::AfterUpdate => 'after_update',
            self::BeforeDelete => 'before_delete',
            self::AfterDelete => 'after_delete',
        });
    }

    /**
     * @return array<string, string> value => label, for a select
     */
    public static function options(): array
    {
        $options = [];

        foreach (self::cases() as $case) {
            $options[$case->value] = $case->label();
        }

        return $options;
    }
}
