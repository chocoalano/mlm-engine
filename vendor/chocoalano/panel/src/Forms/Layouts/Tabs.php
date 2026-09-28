<?php

declare(strict_types=1);

namespace PandaPanel\Forms\Layouts;

use Illuminate\Database\Eloquent\Model;
use PandaPanel\Forms\Components\Field;
use PandaPanel\Forms\Components\FormComponent;

/**
 * A form split into tabs.
 *
 * Layout only, exactly like a wizard: every field in every tab is validated
 * on submit, whichever tab is showing. That is what lets the frontend jump to
 * the tab holding a rejected field without a second definition of which
 * fields those are.
 *
 * The difference from a wizard is order, not structure. A wizard says "these
 * come one after another"; tabs say "these are all here, look at whichever
 * you like". So a wizard validates per step and tabs never do.
 */
final class Tabs extends FormComponent
{
    /** @var list<Tab> */
    private array $tabs = [];

    private bool $persistTab = false;

    /**
     * @param  array<array-key, Tab>  $tabs
     */
    public static function make(array $tabs = []): self
    {
        $instance = new self;
        $instance->tabs = array_values($tabs);

        return $instance;
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
     * Remembers which tab was open across a reload, in the URL.
     */
    public function persistTab(bool $persist = true): self
    {
        $this->persistTab = $persist;

        return $this;
    }

    /**
     * @return list<FormComponent>
     */
    public function children(): array
    {
        return $this->tabs;
    }

    /**
     * @return list<Field>
     */
    public function fields(): array
    {
        $fields = [];

        foreach ($this->tabs as $tab) {
            $fields = [...$fields, ...$tab->fields()];
        }

        return $fields;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(?Model $record, string $page): array
    {
        return [
            'component' => 'tabs',
            'persistTab' => $this->persistTab,
            'tabs' => array_map(
                static fn (Tab $tab): array => $tab->toArray($record, $page),
                $this->tabs,
            ),
        ];
    }
}
