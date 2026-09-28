<?php

declare(strict_types=1);

namespace PandaPanel\Infolists\Layouts;

use Illuminate\Database\Eloquent\Model;
use PandaPanel\Actions\Action;
use PandaPanel\Infolists\Components\Entry;
use PandaPanel\Infolists\Components\InfolistComponent;
use PandaPanel\Support\ColumnCount;

/**
 * A titled group of entries.
 *
 * Layout only, exactly as in a form: moving an entry between sections cannot
 * change what is read or shown.
 */
final class Section extends InfolistComponent
{
    /** @var list<InfolistComponent> */
    private array $components = [];

    private ?string $description = null;

    private int $columns = 1;

    /** @var list<Action> */
    private array $headerActions = [];

    public function __construct(private readonly string $heading) {}

    public static function make(string $heading): self
    {
        return new self($heading);
    }

    /**
     * @param  array<array-key, InfolistComponent>  $components
     */
    public function schema(array $components): self
    {
        $this->components = array_values($components);

        return $this;
    }

    public function description(string $description): self
    {
        $this->description = $description;

        return $this;
    }

    public function columns(int $columns): self
    {
        $this->columns = ColumnCount::clamp($columns);

        return $this;
    }

    /**
     * Operations that belong to this group of entries rather than to one of
     * them — "resend invitation" beside the invitation details.
     *
     * @param  array<array-key, Action>  $actions
     */
    public function headerActions(array $actions): self
    {
        $this->headerActions = array_values($actions);

        return $this;
    }

    /**
     * @return list<Action>
     */
    public function getHeaderActions(): array
    {
        return $this->headerActions;
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
     * A section whose entries are all hidden for this record renders nothing
     * rather than an empty heading.
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
            'component' => 'section',
            'heading' => $this->heading,
            'description' => $this->description,
            'columns' => $this->columns,
            'schema' => $schema,
            // See `Entry::toArray()`: a wrapped repeatable row is not a
            // record an action could be run against.
            'headerActions' => $record->exists
                ? array_values(array_filter(array_map(
                    static fn (Action $action): ?array => $action->toArray($record),
                    $this->headerActions,
                )))
                : [],
        ];
    }
}
