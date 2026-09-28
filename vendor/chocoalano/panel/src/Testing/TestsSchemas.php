<?php

declare(strict_types=1);

namespace PandaPanel\Testing;

use Illuminate\Database\Eloquent\Model;
use PandaPanel\Forms\FormSchema;
use PandaPanel\Infolists\InfolistSchema;
use PandaPanel\Resources\Resource as PanelResource;
use PHPUnit\Framework\Assert;

/**
 * Asking a form or an infolist what it declares.
 *
 * Both are recursive structures — a field can be four layouts deep — so the
 * useful questions ("is this field on the form", "what does it validate")
 * are ones a test should not have to walk the tree to answer.
 *
 * Everything reads the real schema. `fields()` and `validationRules()` are the
 * same methods the controller calls, so a field this finds is a field that
 * validates and dehydrates.
 */
final class TestsSchemas
{
    /**
     * @param  class-string<PanelResource>  $resource
     */
    private function __construct(
        private readonly string $resource,
        private readonly string $page,
    ) {}

    /**
     * @param  class-string<PanelResource>  $resource
     */
    public static function form(string $resource, string $page = 'create'): self
    {
        return new self($resource, $page);
    }

    public function schema(): FormSchema
    {
        return $this->resource::form(
            FormSchema::make()->model($this->resource::getModel())->forPage($this->page),
        );
    }

    /**
     * @return list<string>
     */
    public function fieldNames(?Model $record = null): array
    {
        return array_map(
            static fn ($field): string => $field->getName(),
            $this->schema()->fields($record),
        );
    }

    public function assertHasField(string $name, ?Model $record = null): self
    {
        Assert::assertContains($name, $this->fieldNames($record), sprintf(
            'The %s form of [%s] has no [%s] field.',
            $this->page,
            $this->resource,
            $name,
        ));

        return $this;
    }

    /**
     * A field that is not there is not merely hidden: it is absent from the
     * rules and from what dehydrates, so a request that sends it cannot make
     * it exist. That is what this asserts.
     */
    public function assertDoesNotHaveField(string $name, ?Model $record = null): self
    {
        Assert::assertNotContains($name, $this->fieldNames($record), sprintf(
            'The %s form of [%s] has a [%s] field, and was not expected to.',
            $this->page,
            $this->resource,
            $name,
        ));

        Assert::assertArrayNotHasKey($name, $this->schema()->validationRules($record));

        return $this;
    }

    public function assertFieldIsRequired(string $name, ?Model $record = null): self
    {
        $rules = $this->schema()->validationRules($record)[$name] ?? [];

        Assert::assertContains('required', array_map(strval(...), $rules), sprintf(
            'The [%s] field is not required.',
            $name,
        ));

        return $this;
    }

    /**
     * What the schema would actually write, for the data given.
     *
     * The place most form bugs live: a field that validates and then does not
     * persist, or one that persists under a different name.
     *
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    public function dehydrate(array $validated, ?Model $record = null): array
    {
        return $this->schema()->dehydrate($validated, $record);
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    public function assertDehydratesTo(array $validated, array $expected, ?Model $record = null): self
    {
        Assert::assertSame($expected, $this->dehydrate($validated, $record));

        return $this;
    }

    /**
     * Every entry an infolist renders, however deeply nested.
     *
     * @param  class-string<PanelResource>  $resource
     * @return list<string>
     */
    public static function infolistLabels(string $resource, Model $record): array
    {
        $schema = $resource::infolist(InfolistSchema::make());

        return self::labels($schema->toArray($record)['schema']);
    }

    /**
     * @param  array<int, array<string, mixed>>  $components
     * @return list<string>
     */
    private static function labels(array $components): array
    {
        $labels = [];

        foreach ($components as $component) {
            if (($component['component'] ?? null) === 'entry') {
                $labels[] = (string) $component['label'];
            }

            foreach (['schema', 'tabs'] as $key) {
                if (isset($component[$key]) && is_array($component[$key])) {
                    $labels = [...$labels, ...self::labels($component[$key])];
                }
            }
        }

        return $labels;
    }
}
