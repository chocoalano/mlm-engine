<?php

declare(strict_types=1);

namespace PandaPanel\Tables;

use PandaPanel\Exceptions\PanelSchemaException;
use PandaPanel\Support\ColumnCount;
use PandaPanel\Tables\Columns\Column;
use PandaPanel\Tables\Enums\ColumnType;

/**
 * Which of a table's columns fill which slot on a card.
 *
 * A card face is an arrangement of the columns the table already declares,
 * never a second set of them. A `BadgeColumn` reaching a card renders through
 * the same cell renderer it uses in a row, with the same colours, from the
 * same serialized definition — so a column changed once changes everywhere it
 * is drawn. The alternative, a parallel card schema, would have been a second
 * discriminated union to keep exhaustive and a second place for the same
 * column to disagree with itself.
 *
 * This class therefore holds **column names**, never `Column` objects, and
 * resolves nothing until `toArray()` is handed the schema's columns.
 *
 * Five slots and no more. There is no footer slot because the record actions
 * are the footer, and they are already resolved per row by
 * `TableSchema::toRow()` with authorization applied.
 */
final class CardLayout
{
    /**
     * How many value rows inference will put on a card.
     *
     * Inference only — an explicit `details()` is taken as written. A table
     * with thirty columns would otherwise infer a thirty-row card, which is a
     * table with rounded corners rather than a card.
     */
    private const INFERRED_DETAILS = 4;

    /**
     * The column types that cannot be a heading.
     *
     * A `Switch` or a text box as the card's title is not a title. These are
     * skipped when inferring, though naming one explicitly is allowed: the
     * declaration is then somebody's decision rather than this class's guess.
     */
    private const NOT_A_HEADING = [
        ColumnType::Toggle,
        ColumnType::Checkbox,
        ColumnType::TextInput,
        ColumnType::Select,
    ];

    /** The types that already read as a chip. */
    private const BADGE_TYPES = [
        ColumnType::Badge,
        ColumnType::Boolean,
        ColumnType::Icon,
    ];

    private ?string $title = null;

    private bool $inferTitle = true;

    private ?string $description = null;

    private bool $inferImage = true;

    private ?string $image = null;

    /** @var list<string>|null */
    private ?array $badges = null;

    /** @var list<string>|null */
    private ?array $details = null;

    private int $columns = 3;

    public static function make(): self
    {
        return new self;
    }

    /**
     * The card's heading. `null` leaves it empty rather than inferring one.
     */
    public function title(?string $column): self
    {
        $this->title = $column;
        $this->inferTitle = false;

        return $this;
    }

    /**
     * A line under the heading.
     *
     * Never inferred, unlike every other slot — see `resolve()`.
     */
    public function description(?string $column): self
    {
        $this->description = $column;

        return $this;
    }

    /**
     * The card's picture. `null` leaves it out rather than inferring one.
     */
    public function image(?string $column): self
    {
        $this->image = $column;
        $this->inferImage = false;

        return $this;
    }

    /**
     * The chips beside the heading. `[]` is a declared empty slot.
     *
     * @param  list<string>|null  $columns
     */
    public function badges(?array $columns): self
    {
        $this->badges = $columns === null ? [] : array_values($columns);

        return $this;
    }

    /**
     * The label and value rows in the card's body. `[]` is a declared empty
     * slot.
     *
     * @param  list<string>|null  $columns
     */
    public function details(?array $columns): self
    {
        $this->details = $columns === null ? [] : array_values($columns);

        return $this;
    }

    /**
     * Cards per row.
     *
     * Clamped through `ColumnCount` because `panel/lib/grid.ts` only has
     * literal Tailwind classes up to four, and an interpolated
     * `grid-cols-${n}` is invisible to the compiler — the grid would silently
     * collapse to one column.
     */
    public function columns(int $count): self
    {
        $this->columns = ColumnCount::clamp($count);

        return $this;
    }

    /**
     * The face, as column names, checked against the columns that exist.
     *
     * Validation happens here rather than in the setters for the reason
     * `TableSchema::assertKnownDefaultSort()` gives: `cards()` may be called
     * before `columns()`, and there would be nothing to check against yet.
     *
     * @param  list<Column>  $columns
     * @return array<string, mixed>
     *
     * @throws PanelSchemaException
     */
    public function toArray(array $columns): array
    {
        $known = [];

        foreach ($columns as $column) {
            $known[$column->getName()] = $column;
        }

        $this->assertKnown('title', $this->title === null ? [] : [$this->title], $known);
        $this->assertKnown('description', $this->description === null ? [] : [$this->description], $known);
        $this->assertKnown('image', $this->image === null ? [] : [$this->image], $known);
        $this->assertKnown('badges', $this->badges ?? [], $known);
        $this->assertKnown('details', $this->details ?? [], $known);

        return $this->resolve($columns) + ['columns' => $this->columns];
    }

    /**
     * Fills the slots nobody declared.
     *
     * Runs over the columns in declared order, considering only the ones the
     * table starts with visible — a column hidden by default is not on the
     * card either. Explicit slots are taken first and inference never reuses
     * a column an explicit slot claimed, so declaring one thing does not
     * silently duplicate it somewhere else on the same card.
     *
     * @param  list<Column>  $columns
     * @return array<string, mixed>
     */
    private function resolve(array $columns): array
    {
        $claimed = array_filter([
            $this->title,
            $this->description,
            $this->image,
            ...($this->badges ?? []),
            ...($this->details ?? []),
        ], static fn (?string $name): bool => $name !== null);

        /** @var list<Column> $available */
        $available = array_values(array_filter(
            $columns,
            fn (Column $column): bool => $column->isVisible()
                && ! in_array($column->getName(), $claimed, true),
        ));

        $take = function (?Column $column) use (&$available): ?string {
            if ($column === null) {
                return null;
            }

            $available = array_values(array_filter(
                $available,
                static fn (Column $other): bool => $other !== $column,
            ));

            return $column->getName();
        };

        $image = $this->inferImage
            ? $take($this->first($available, static fn (Column $c): bool => $c->type() === ColumnType::Image))
            : $this->image;

        $title = $this->title;

        if ($this->inferTitle) {
            $heading = $this->first(
                $available,
                static fn (Column $c): bool => ! in_array($c->type(), self::NOT_A_HEADING, true),
            );

            $title = $take($heading ?? ($available[0] ?? null));
        }

        // A description is never inferred. Every other slot has a rule that is
        // right more often than not; there is no such rule for a subtitle, and
        // two lines of near-identical text reads worse than one line.
        $badges = $this->badges ?? array_values(array_map(
            $take,
            array_filter(
                $available,
                static fn (Column $c): bool => in_array($c->type(), self::BADGE_TYPES, true),
            ),
        ));

        $details = $this->details ?? array_map(
            static fn (Column $c): string => $c->getName(),
            array_slice($available, 0, self::INFERRED_DETAILS),
        );

        return [
            'image' => $image,
            'title' => $title,
            'description' => $this->description,
            'badges' => array_values(array_filter($badges, static fn (?string $n): bool => $n !== null)),
            'details' => array_values($details),
        ];
    }

    /**
     * @param  list<Column>  $columns
     * @param  callable(Column): bool  $matches
     */
    private function first(array $columns, callable $matches): ?Column
    {
        foreach ($columns as $column) {
            if ($matches($column)) {
                return $column;
            }
        }

        return null;
    }

    /**
     * @param  list<string>  $names
     * @param  array<string, Column>  $known
     *
     * @throws PanelSchemaException
     */
    private function assertKnown(string $slot, array $names, array $known): void
    {
        foreach ($names as $name) {
            if (! array_key_exists($name, $known)) {
                throw PanelSchemaException::unknownCardColumn($slot, $name, array_keys($known));
            }
        }
    }
}
