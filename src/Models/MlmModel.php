<?php

declare(strict_types=1);

namespace PandaBear\Mlm\Models;

use Illuminate\Container\Container;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use PandaBear\Mlm\Support\PandaMlmConfig;

/**
 * Technical behaviour every package model shares: a ULID key and the
 * package's configured connection. No business rules belong here.
 */
abstract class MlmModel extends Model
{
    use HasUlids;

    /**
     * A connection set on the model — `Program::on('x')`, or one Eloquent
     * hydrated it with — wins, as it does for any model. Otherwise the
     * package's configured connection, where null is the application default.
     */
    public function getConnectionName(): ?string
    {
        return parent::getConnectionName()
            ?? Container::getInstance()->make(PandaMlmConfig::class)->databaseConnection();
    }
}
