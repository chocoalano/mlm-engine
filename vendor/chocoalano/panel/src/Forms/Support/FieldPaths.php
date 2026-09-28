<?php

declare(strict_types=1);

namespace PandaPanel\Forms\Support;

use PandaPanel\Forms\Components\Builder;
use PandaPanel\Forms\Components\Field;
use PandaPanel\Forms\Components\FormComponent;
use PandaPanel\Forms\Components\Repeater;

/**
 * Every place a schema writes to, by the address it writes there.
 *
 * A form's real key is not a field's name; it is where that name sits in the
 * state. `bio` at the top level and `bio` inside a `Relationship('profile')`
 * are two different places — the second is `profile.bio` — and `title` inside
 * two different repeaters is two different places as well. A check that only
 * compared names would refuse the first pair and miss a genuine collision in
 * the second.
 *
 * ## Why this matters
 *
 * Two fields at one address is not a cosmetic problem. Only one rule survives
 * into the validator and only one value survives into the write, so the other
 * field is rendered, filled in, submitted, and discarded without a word. The
 * symptom is a value that will not save and a form that looks entirely
 * correct, which is close to undiagnosable from the outside — hence naming it
 * at the moment the schema is compiled.
 *
 * ## The addresses
 *
 * | Declaration                                        | Address           |
 * | -------------------------------------------------- | ----------------- |
 * | `TextInput::make('name')`                           | `name`            |
 * | inside `Relationship('profile')`                    | `profile.bio`     |
 * | inside `Repeater::make('items')`                    | `items.*.title`   |
 * | inside `Builder::make('body')`'s `paragraph` block  | `body.*.paragraph.text` |
 *
 * Layouts — sections, grids, tabs, wizard steps — are presentation and add no
 * segment. Two fields in two different tabs are two fields in one form.
 *
 * A repeater's own entries share one address by construction: every entry has
 * a `title`, which is what `*` says. Walking into it once rather than once per
 * entry is what stops that being read as a duplicate.
 */
final class FieldPaths
{
    /**
     * Every address in a schema, in declaration order, with duplicates kept.
     *
     * Kept deliberately: the list is the input to the duplicate check, and
     * de-duplicating it here would delete the thing being looked for.
     *
     * @param  list<FormComponent>  $components
     * @return list<array{path: string, field: string, owner: string|null}>
     */
    public static function collect(array $components, string $prefix = '', ?string $owner = null): array
    {
        $paths = [];

        foreach ($components as $component) {
            $paths = [...$paths, ...self::walk($component, $prefix, $owner)];
        }

        return $paths;
    }

    /**
     * The addresses written more than once, each with what is at it.
     *
     * @param  list<FormComponent>  $components
     * @return array<string, list<string>> address => the owners that declared it
     */
    public static function duplicates(array $components): array
    {
        $seen = [];

        foreach (self::collect($components) as $entry) {
            $seen[$entry['path']][] = $entry['owner'] ?? 'form';
        }

        return array_filter($seen, static fn (array $owners): bool => count($owners) > 1);
    }

    /**
     * @return list<array{path: string, field: string, owner: string|null}>
     */
    private static function walk(FormComponent $component, string $prefix, ?string $owner): array
    {
        // A repeater and a builder hold a list of entries rather than one set
        // of values, so their children live one wildcard deeper. Both report
        // themselves through `fields()`, which is why they are matched here
        // rather than found by recursing into `children()`.
        if ($component instanceof Repeater) {
            $path = $prefix.$component->getName();

            return [
                ['path' => $path, 'field' => $component->getName(), 'owner' => $owner],
                ...self::collect($component->componentsForPaths(), $path.'.*.', $path),
            ];
        }

        if ($component instanceof Builder) {
            $path = $prefix.$component->getName();
            $paths = [['path' => $path, 'field' => $component->getName(), 'owner' => $owner]];

            // Each block is its own namespace: two blocks may both hold a
            // `text`, and an entry only ever belongs to one of them.
            foreach ($component->blocksForPaths() as $block) {
                $blockPath = $path.'.*.'.$block->getName();

                $paths = [
                    ...$paths,
                    ...self::collect($block->componentsForPaths(), $blockPath.'.', $blockPath),
                ];
            }

            return $paths;
        }

        if ($component instanceof Field) {
            // `getName()` already carries a relation group's prefix, so a
            // second one here would produce `profile.profile.bio`.
            return [[
                'path' => $prefix.$component->getName(),
                'field' => $component->getName(),
                'owner' => $owner,
            ]];
        }

        // Every other layout is presentation. It adds no segment, and two
        // fields in two tabs are two fields in one form.
        return self::collect($component->children(), $prefix, $owner);
    }
}
