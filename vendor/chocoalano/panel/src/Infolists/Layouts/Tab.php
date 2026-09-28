<?php

declare(strict_types=1);

namespace PandaPanel\Infolists\Layouts;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use PandaPanel\Infolists\Components\Entry;
use PandaPanel\Infolists\Components\InfolistComponent;
use PandaPanel\Support\ColumnCount;

/**
 * One panel of a tab set.
 *
 * Layout only, exactly as in a form: which tab an entry sits in cannot change
 * what it reads.
 */
final class Tab extends InfolistComponent
{
    /** @var list<InfolistComponent> */
    private array $components = [];

    private ?string $icon = null;

    private ?string $badge = null;

    private int $columns = 1;

    public function __construct(private readonly string $label) {}

    public static function make(string $label): self
    {
        return new self($label);
    }

    /**
     * @param  array<array-key, InfolistComponent>  $components
     */
    public function schema(array $components): self
    {
        $this->components = array_values($components);

        return $this;
    }

    public function icon(string $icon): self
    {
        $this->icon = $icon;

        return $this;
    }

    public function badge(string $badge): self
    {
        $this->badge = $badge;

        return $this;
    }

    public function columns(int $columns): self
    {
        $this->columns = ColumnCount::clamp($columns);

        return $this;
    }

    /**
     * @return list<Entry>
     */
    public function entries(): array
    {
        $entries = [];

        foreach ($this->components as $component) {
            $entries = [...$entries, ...$component->entries()];
        }

        return $entries;
    }

    /**
     * A tab whose entries are all hidden for this record is not rendered, so
     * nobody opens a panel to find it empty.
     *
     * @return array<string, mixed>|null
     */
    public function toArray(Model $record): ?array
    {
        $schema = [];

        foreach ($this->components as $component) {
            $child = $component->toArray($record);

            if ($child !== null) {
                $schema[] = $child;
            }
        }

        if ($schema === []) {
            return null;
        }

        return [
            'component' => 'tab',
            'label' => $this->label,
            'key' => Str::slug($this->label),
            'icon' => $this->icon,
            'badge' => $this->badge,
            'columns' => $this->columns,
            'schema' => $schema,
        ];
    }
}
