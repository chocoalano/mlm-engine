<?php

declare(strict_types=1);

namespace PandaPanel\Tables\Filters;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use PandaPanel\Tables\Enums\FilterType;

/**
 * Chooses which soft-deleted records the table shows.
 *
 * Default rather than absent: leaving the filter off means the query keeps
 * Eloquent's own `withoutTrashed` scope, so a table that declares this filter
 * still hides deleted rows until somebody asks for them. The three states are
 * the whole vocabulary — `sanitize()` rejects everything else, so the query
 * can never be widened by a value the filter did not define.
 *
 * Declaring this on a table whose model does not soft delete would produce a
 * builder without those macros, so `apply()` checks before calling them and
 * a mistake shows up as a filter that does nothing rather than a 500.
 */
final class TrashedFilter extends Filter
{
    public const WITHOUT = 'without';

    public const WITH = 'with';

    public const ONLY = 'only';

    public function type(): FilterType
    {
        return FilterType::Select;
    }

    public function getLabel(): string
    {
        return $this->label ?? __('panda-panel::tables.trashed_filter.label');
    }

    public function sanitize(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        return in_array($value, [self::WITHOUT, self::WITH, self::ONLY], true) ? $value : null;
    }

    protected function constrain(Builder $query, mixed $value): void
    {
        $model = $query->getModel();

        // A model that does not soft delete has no deleted-at column, so
        // there is nothing here to include or exclude.
        if (! method_exists($model, 'getQualifiedDeletedAtColumn')) {
            return;
        }

        // Lifting the scope by hand rather than through the `withTrashed`
        // macro: the macros only exist on a builder the trait extended, and
        // this is the same mechanism they use.
        match ($value) {
            self::WITH => $query->withoutGlobalScope(SoftDeletingScope::class),
            self::ONLY => $query
                ->withoutGlobalScope(SoftDeletingScope::class)
                ->whereNotNull($model->getQualifiedDeletedAtColumn()),
            default => $query,
        };
    }

    /**
     * The three states, and what each is called on screen.
     *
     * One map rather than a list built in `extraArray()` and a second reading
     * of it elsewhere: the dropdown and the chip have to agree about what
     * `only` is called, and they can only be relied on to agree if there is
     * one place that says so.
     *
     * A method rather than a constant, because the names are translated and
     * a constant is built before the translator can answer.
     *
     * @return array<string, string>
     */
    private static function labels(): array
    {
        return [
            self::WITHOUT => __('panda-panel::tables.trashed_filter.without'),
            self::WITH => __('panda-panel::tables.trashed_filter.with'),
            self::ONLY => __('panda-panel::tables.trashed_filter.only'),
        ];
    }

    /**
     * The chip says the state's name, not its key: `only` is "Only deleted".
     */
    protected function describe(mixed $value): string
    {
        $key = is_string($value) ? $value : '';

        return self::labels()[$key] ?? $key;
    }

    /**
     * @return array<string, mixed>
     */
    protected function extraArray(): array
    {
        $labels = self::labels();

        return [
            'options' => array_map(
                static fn (string $value, string $label): array => [
                    'value' => $value,
                    'label' => $label,
                ],
                array_keys($labels),
                array_values($labels),
            ),
            'placeholder' => $labels[self::WITHOUT],
        ];
    }
}
