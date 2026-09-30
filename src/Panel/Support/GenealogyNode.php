<?php

declare(strict_types=1);

namespace PandaBear\Mlm\Panel\Support;

use Illuminate\Database\Eloquent\Model;

/**
 * One row of the genealogy explorer. Never persisted: it only carries a
 * computed node to the table the explorer draws.
 */
final class GenealogyNode extends Model
{
    public $incrementing = false;

    public $timestamps = false;

    protected $keyType = 'string';

    /** @var list<string> */
    protected $guarded = [];
}
