<?php

declare(strict_types=1);

namespace PandaPanel\Infolists\Components;

use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use PandaPanel\Actions\Action;
use PandaPanel\Infolists\Enums\EntryType;
use PandaPanel\Support\Label;

/**
 * Base for every infolist entry.
 *
 * An entry owns two things: how it describes itself to the frontend, and how
 * a record becomes a serializable value. Neither may leak a closure, a
 * model, or a query — the same boundary a table column keeps.
 *
 * Dot notation reads through relations, so `author.name` is an entry rather
 * than a reason to write a formatter.
 */
abstract class Entry extends InfolistComponent
{
    protected ?string $label = null;

    protected ?string $placeholder = null;

    protected ?string $helperText = null;

    /**
     * How many of the container's columns this entry takes, or `'full'` for
     * the whole row. See `Field::$columnSpan` for why the whole row is not
     * spelled as a number.
     *
     * @var int|'full'
     */
    protected int|string $columnSpan = 1;

    /** @var (Closure(mixed, Model): mixed)|null */
    protected ?Closure $formatUsing = null;

    /** @var (Closure(Model): bool)|null */
    protected ?Closure $visibleUsing = null;

    protected ?Action $action = null;

    final public function __construct(protected readonly string $name) {}

    public static function make(string $name): static
    {
        return new static($name);
    }

    abstract public function type(): EntryType;

    public function getName(): string
    {
        return $this->name;
    }

    public function label(string $label): static
    {
        $this->label = $label;

        return $this;
    }

    public function getLabel(): string
    {
        return $this->label ?? Label::resolve(
            'fields',
            $this->name,
            fn (): string => Str::headline(str_replace('.', ' ', $this->name)),
        );
    }

    /**
     * Shown when the value is null or empty, instead of a blank space that
     * reads as a rendering bug.
     */
    public function placeholder(string $placeholder): static
    {
        $this->placeholder = $placeholder;

        return $this;
    }

    public function helperText(string $helperText): static
    {
        $this->helperText = $helperText;

        return $this;
    }

    public function columnSpan(int $columnSpan): static
    {
        $this->columnSpan = max(1, $columnSpan);

        return $this;
    }

    /**
     * The whole row, whatever the container turns out to be divided into.
     */
    public function columnSpanFull(): static
    {
        $this->columnSpan = 'full';

        return $this;
    }

    /**
     * @param  Closure(mixed, Model): mixed  $callback
     */
    public function formatUsing(Closure $callback): static
    {
        $this->formatUsing = $callback;

        return $this;
    }

    /**
     * Evaluated on the server; only the outcome crosses, never the closure.
     *
     * @param  Closure(Model): bool  $callback
     */
    public function visible(Closure $callback): static
    {
        $this->visibleUsing = $callback;

        return $this;
    }

    public function isVisible(Model $record): bool
    {
        return $this->visibleUsing === null || ($this->visibleUsing)($record);
    }

    /**
     * An operation offered beside the value.
     *
     * The same `Action` a table row or a header uses, so a thing that can be
     * done to a record is described once however it is reached. It is
     * serialized against this record, which means an action the user may not
     * run is simply not there rather than a button that answers 403.
     */
    public function action(Action $action): static
    {
        $this->action = $action;

        return $this;
    }

    public function getAction(): ?Action
    {
        return $this->action;
    }

    /**
     * @return list<Entry>
     */
    public function entries(): array
    {
        return [$this];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function toArray(Model $record): ?array
    {
        if (! $this->isVisible($record)) {
            return null;
        }

        return [
            'component' => 'entry',
            'name' => $this->name,
            'label' => $this->getLabel(),
            'type' => $this->type()->value,
            'value' => $this->toValue($record),
            'placeholder' => $this->placeholder,
            'helperText' => $this->helperText,
            'columnSpan' => $this->columnSpan,
            // Only for a record that is one. Inside a repeatable the "record"
            // is a wrapped row with no key, and an action pointing at it
            // would name a record the endpoint could never find.
            'action' => $record->exists ? $this->action?->toArray($record) : null,
            ...$this->extraArray(),
        ];
    }

    /**
     * The serialized value. Scalars, arrays, and nulls only.
     */
    abstract public function toValue(Model $record): mixed;

    protected function resolveValue(Model $record): mixed
    {
        $value = data_get($record, $this->name);

        return $this->formatUsing === null
            ? $value
            : ($this->formatUsing)($value, $record);
    }

    /**
     * @return array<string, mixed>
     */
    protected function extraArray(): array
    {
        return [];
    }
}
