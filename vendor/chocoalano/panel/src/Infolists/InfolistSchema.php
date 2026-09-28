<?php

declare(strict_types=1);

namespace PandaPanel\Infolists;

use Illuminate\Database\Eloquent\Model;
use PandaPanel\Actions\Action;
use PandaPanel\Infolists\Components\Entry;
use PandaPanel\Infolists\Components\InfolistComponent;
use PandaPanel\Infolists\Layouts\Section;
use PandaPanel\Support\ColumnCount;

/**
 * A record's read-only presentation.
 *
 * Deliberately separate from `FormSchema` rather than a mode of it. A form
 * validates, dehydrates, and hides fields per page; an infolist does none of
 * that. Sharing one class would mean every entry carrying rules it can never
 * use, and a view page quietly depending on what the edit form happens to
 * declare.
 */
final class InfolistSchema
{
    /** @var list<InfolistComponent> */
    private array $components = [];

    private int $columns = 1;

    /** @var list<Action> */
    private array $actions = [];

    public static function make(): self
    {
        return new self;
    }

    /**
     * @param  array<array-key, InfolistComponent>  $components
     */
    public function schema(array $components): self
    {
        $this->components = array_values($components);

        return $this;
    }

    public function columns(int $columns): self
    {
        $this->columns = ColumnCount::clamp($columns);

        return $this;
    }

    /**
     * Operations offered for the record as a whole.
     *
     * They sit above the infolist rather than beside a value, which is where
     * "approve this order" belongs — it is about the record, not about one of
     * its columns.
     *
     * @param  array<array-key, Action>  $actions
     */
    public function actions(array $actions): self
    {
        $this->actions = array_values($actions);

        return $this;
    }

    /**
     * Every action the infolist declares, wherever it sits.
     *
     * This is the whitelist the endpoint resolves against: an action that is
     * not in here does not exist, however the request spells it. Header
     * actions, section actions, and entry actions are all reachable because
     * all three were declared by this schema — and nothing else is.
     *
     * @return array<string, Action>
     */
    public function allActions(): array
    {
        $actions = [];

        foreach ($this->actions as $action) {
            $actions[$action->getName()] = $action;
        }

        foreach ($this->components as $component) {
            if ($component instanceof Section) {
                foreach ($component->getHeaderActions() as $action) {
                    $actions[$action->getName()] = $action;
                }
            }
        }

        foreach ($this->entries() as $entry) {
            $action = $entry->getAction();

            if ($action !== null) {
                $actions[$action->getName()] = $action;
            }
        }

        // An action reachable from inside another's dialog is reachable, so
        // the lookup has to know about it — but only through its parent,
        // which is what registering it there meant.
        foreach ($actions as $action) {
            foreach ($action->getModalActions() as $nested) {
                $actions[$nested->getName()] ??= $nested;
            }
        }

        return $actions;
    }

    public function getAction(string $name): ?Action
    {
        return $this->allActions()[$name] ?? null;
    }

    /**
     * @return list<InfolistComponent>
     */
    public function getComponents(): array
    {
        return $this->components;
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

    public function isEmpty(): bool
    {
        return $this->components === [];
    }

    /**
     * @return array{columns: int, schema: list<array<string, mixed>>, actions: list<array<string, mixed>>}
     */
    public function toArray(Model $record): array
    {
        $schema = [];

        foreach ($this->components as $component) {
            $child = $component->toArray($record);

            if ($child !== null) {
                $schema[] = $child;
            }
        }

        return [
            'columns' => $this->columns,
            'schema' => $schema,
            'actions' => array_values(array_filter(array_map(
                static fn (Action $action): ?array => $action->toArray($record),
                $this->actions,
            ))),
        ];
    }
}
