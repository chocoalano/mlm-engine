<?php

declare(strict_types=1);

namespace PandaPanel\Infolists\Support;

use Illuminate\Database\Eloquent\Model;

/**
 * One row of a repeatable that is not a record.
 *
 * A `RepeatableEntry` over a relation hands its children real models. Over a
 * JSON column it has rows of a plain array, and the children still have to be
 * able to read `title` out of one — so a row is wrapped in this rather than
 * every entry, closure, and signature in the infolist learning to accept
 * `Model|array`.
 *
 * Widening was the other option and it was worse: it would have broken every
 * `formatUsing(fn (mixed $value, Model $record) => …)` a panel has already
 * written, to describe a case that only exists inside one component.
 *
 * Never saved, never queried, has no table. It exists for the length of one
 * `toArray()`.
 */
final class InfolistRow extends Model
{
    /** @var list<string> */
    protected $guarded = [];

    public $timestamps = false;

    /**
     * @param  array<array-key, mixed>  $row
     */
    public static function wrap(array $row): self
    {
        $model = new self;

        // `forceFill` rather than `fill`: these attributes are the panel's
        // own data, not request input, and there is no table to guard.
        $model->forceFill(
            array_combine(
                array_map(strval(...), array_keys($row)),
                array_values($row),
            ),
        );

        return $model;
    }
}
