<?php

declare(strict_types=1);

namespace PandaPanel\Infolists\Layouts;

use Illuminate\Database\Eloquent\Model;
use PandaPanel\Infolists\Components\Entry;
use PandaPanel\Infolists\Components\InfolistComponent;

/**
 * A record shown a few panels at a time.
 *
 * For a record with more to say than fits on one screen. Every tab is
 * serialized, because they are all the same record read different ways —
 * fetching a tab when it opens would be a request to show data the page
 * already had.
 */
final class Tabs extends InfolistComponent
{
    /** @var list<Tab> */
    private array $tabs = [];

    private bool $persistTab = false;

    /**
     * @param  array<array-key, Tab>  $tabs
     */
    public static function make(array $tabs = []): self
    {
        return (new self)->tabs($tabs);
    }

    /**
     * @param  array<array-key, Tab>  $tabs
     */
    public function tabs(array $tabs): self
    {
        $this->tabs = array_values($tabs);

        return $this;
    }

    /**
     * Remembers the open tab in the URL, so a reload — or a link somebody was
     * sent — opens where it was left.
     */
    public function persistTab(bool $persist = true): self
    {
        $this->persistTab = $persist;

        return $this;
    }

    /**
     * @return list<Entry>
     */
    public function entries(): array
    {
        $entries = [];

        foreach ($this->tabs as $tab) {
            $entries = [...$entries, ...$tab->entries()];
        }

        return $entries;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function toArray(Model $record): ?array
    {
        $tabs = [];

        foreach ($this->tabs as $tab) {
            $child = $tab->toArray($record);

            if ($child !== null) {
                $tabs[] = $child;
            }
        }

        if ($tabs === []) {
            return null;
        }

        return [
            'component' => 'tabs',
            'persistTab' => $this->persistTab,
            'tabs' => $tabs,
        ];
    }
}
