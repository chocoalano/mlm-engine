<?php

declare(strict_types=1);

namespace PandaBear\Mlm\Exceptions;

use InvalidArgumentException;

final class InvalidExternalIdentity extends InvalidArgumentException
{
    public static function incomplete(?string $type, ?string $id): self
    {
        return new self(sprintf(
            'A member\'s external identity needs both external_type and external_id as non-empty strings, or neither; got type %s and id %s.',
            var_export($type, true),
            var_export($id, true),
        ));
    }
}
