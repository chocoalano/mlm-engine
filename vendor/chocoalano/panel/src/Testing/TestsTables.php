<?php

declare(strict_types=1);

namespace PandaPanel\Testing;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use PandaPanel\Resources\Resource as PanelResource;
use PandaPanel\Tables\TableQuery;
use PandaPanel\Tables\TableSchema;
use PHPUnit\Framework\Assert;

/**
 * An expressive way to ask a table what it is showing.
 *
 * The alternative — and what the panel suite did before this existed — is
 * building a `TableQuery` by hand in every test and digging through the
 * paginator. That works, and it means each test restates how the table
 * builder is wired, so a change to the wiring is a change to fifty tests.
 *
 * Everything here goes through the real `TableSchema` and `TableQuery`. It is
 * a nicer way to *ask*, never a second implementation of the answer: a helper
 * that computed its own idea of what a table shows would pass while the table
 * was broken.
 */
final class TestsTables
{
    /**
     * @param  class-string<PanelResource>  $resource
     * @param  array<string, mixed>  $state  the query string the list is showing
     */
    private function __construct(
        private readonly string $resource,
        private array $state = [],
    ) {}

    /**
     * @param  class-string<PanelResource>  $resource
     */
    public static function for(string $resource): self
    {
        return new self($resource);
    }

    /**
     * Filters the table, as a URL would.
     *
     * @param  array<string, mixed>  $values
     */
    public function filter(array $values): self
    {
        $clone = clone $this;
        $clone->state['filters'] = [...($this->state['filters'] ?? []), ...$values];

        return $clone;
    }

    public function search(string $term): self
    {
        $clone = clone $this;
        $clone->state['search'] = $term;

        return $clone;
    }

    public function sort(string $column, string $direction = 'asc'): self
    {
        $clone = clone $this;
        $clone->state['sort'] = $column;
        $clone->state['direction'] = $direction;

        return $clone;
    }

    public function page(int $page): self
    {
        $clone = clone $this;
        $clone->state['page'] = $page;

        return $clone;
    }

    /**
     * The records the table is currently showing, in order.
     *
     * @return list<Model>
     */
    public function records(): array
    {
        $schema = $this->schema();
        $request = Request::create('/', 'GET', $this->state);

        $request->setLaravelSession(app('session.store'));

        return array_values(
            (new TableQuery($schema, $request))
                ->paginate($this->resource::query())
                ->items(),
        );
    }

    /**
     * @return list<int|string>
     */
    public function keys(): array
    {
        return array_map(
            static fn (Model $record): int|string => $record->getKey(),
            $this->records(),
        );
    }

    /**
     * One row as the frontend receives it, so an assertion can be about what
     * is *shown* rather than what was loaded.
     *
     * @return array<string, mixed>
     */
    public function row(Model $record): array
    {
        return $this->schema()->toRow($record);
    }

    public function assertCanSeeRecord(Model $record): self
    {
        Assert::assertContains(
            $record->getKey(),
            $this->keys(),
            sprintf(
                'Expected [%s#%s] in the table, but it was not there.',
                $record::class,
                (string) $record->getKey(),
            ),
        );

        return $this;
    }

    public function assertCanNotSeeRecord(Model $record): self
    {
        Assert::assertNotContains(
            $record->getKey(),
            $this->keys(),
            sprintf(
                'Expected [%s#%s] not to be in the table, but it was.',
                $record::class,
                (string) $record->getKey(),
            ),
        );

        return $this;
    }

    /**
     * @param  array<array-key, Model>  $records
     */
    public function assertCanSeeRecords(array $records): self
    {
        foreach ($records as $record) {
            $this->assertCanSeeRecord($record);
        }

        return $this;
    }

    public function assertCount(int $count): self
    {
        Assert::assertCount($count, $this->records());

        return $this;
    }

    /**
     * The order matters here, which is what makes this the sort assertion.
     *
     * @param  array<array-key, Model>  $records
     */
    public function assertRecordsInOrder(array $records): self
    {
        Assert::assertSame(
            array_map(static fn (Model $record): int|string => $record->getKey(), array_values($records)),
            $this->keys(),
            'The table is not showing those records in that order.',
        );

        return $this;
    }

    /**
     * What one cell renders as.
     */
    public function assertCellEquals(Model $record, string $column, mixed $expected): self
    {
        $cells = $this->row($record)['cells'] ?? [];

        Assert::assertSame($expected, $cells[$column] ?? null, sprintf(
            'The [%s] cell is not what was expected.',
            $column,
        ));

        return $this;
    }

    public function assertColumnExists(string $column): self
    {
        Assert::assertNotNull(
            $this->schema()->getColumn($column),
            sprintf('The table has no [%s] column.', $column),
        );

        return $this;
    }

    /**
     * The table as the resource declares it. Built fresh each time, because a
     * schema holds resolved state and reusing one across assertions would
     * make each depend on the last.
     */
    public function schema(): TableSchema
    {
        return $this->resource::table(TableSchema::make());
    }
}
