<?php

declare(strict_types=1);

namespace PandaPanel\Support;

use Illuminate\Http\Request;

/**
 * The query string the list was showing when an action was started.
 *
 * A table action posts to an endpoint of its own, so the filters, the search
 * term, and the sort that were on screen are not in the request unless they
 * are sent — and an export that ignored them would hand back a different set
 * of records from the one the user was looking at.
 *
 * Taking it from the request is safe because it is not a permission. Every
 * value goes back through the table's own schema, which is the whitelist: a
 * filter the table never declared is ignored there exactly as it is when it
 * arrives in a URL. The worst a crafted payload can do is describe a list the
 * user could have navigated to.
 */
final class TableState
{
    /**
     * @return array<string, mixed>
     */
    public static function fromRequest(?Request $request = null): array
    {
        $state = ($request ?? request())->input('tableState');

        if (! is_array($state)) {
            return [];
        }

        $narrowed = [];

        foreach ($state as $key => $value) {
            // Scalars and one level of array — which is every shape the table
            // state has. Anything deeper is not something a query string can
            // hold, so it did not come from one.
            if (is_string($key) && (is_scalar($value) || is_array($value))) {
                $narrowed[$key] = $value;
            }
        }

        return $narrowed;
    }
}
