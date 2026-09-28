<?php

declare(strict_types=1);

namespace PandaPanel\Support;

use BackedEnum;
use UnitEnum;

/**
 * A navigation group named by a string or by an enum.
 *
 * An enum is worth reaching for the moment more than one class names the same
 * group: a mistyped string is a second group that looks like the first and
 * silently splits the sidebar in two, while a mistyped enum case does not
 * compile.
 *
 * Everything is a string by the time it reaches the registry, because a group
 * is matched by label — including against the labels a panel declared, which
 * may themselves have been given either way.
 */
final class NavigationGroupName
{
    public static function resolve(string|BackedEnum|UnitEnum|null $group): ?string
    {
        if ($group === null || is_string($group)) {
            return $group;
        }

        if ($group instanceof BackedEnum) {
            return (string) $group->value;
        }

        // A pure enum has no value to take, so the case name is the label —
        // which is what somebody writing `NavigationGroup::Settings` meant.
        return $group->name;
    }

    /**
     * @param  array<array-key, string|BackedEnum|UnitEnum>  $groups
     * @return list<string>
     */
    public static function resolveAll(array $groups): array
    {
        $resolved = [];

        foreach ($groups as $group) {
            $label = self::resolve($group);

            if ($label !== null) {
                $resolved[] = $label;
            }
        }

        return $resolved;
    }
}
