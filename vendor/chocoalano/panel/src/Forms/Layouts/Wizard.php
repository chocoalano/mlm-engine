<?php

declare(strict_types=1);

namespace PandaPanel\Forms\Layouts;

use Illuminate\Database\Eloquent\Model;
use PandaPanel\Forms\Components\Field;
use PandaPanel\Forms\Components\FormComponent;

/**
 * A form split into steps.
 *
 * Presentation only. Validation stays whole and server-side: every field in
 * every step is validated on submit, and the frontend jumps to the first
 * step holding a rejected field.
 *
 * Validating step by step would mean a round trip per step and a second
 * definition of what each step contains. This way there is one validation
 * pass, one definition, and a wizard cannot disagree with the form it is.
 */
final class Wizard extends FormComponent
{
    /** @var list<Step> */
    private array $steps = [];

    private string $submitLabel = 'Submit';

    /**
     * @param  array<array-key, Step>  $steps
     */
    public static function make(array $steps = []): self
    {
        $wizard = new self;
        $wizard->steps = array_values($steps);

        return $wizard;
    }

    /**
     * @param  array<array-key, Step>  $steps
     */
    public function steps(array $steps): self
    {
        $this->steps = array_values($steps);

        return $this;
    }

    public function submitLabel(string $submitLabel): self
    {
        $this->submitLabel = $submitLabel;

        return $this;
    }

    /**
     * @return list<FormComponent>
     */
    public function children(): array
    {
        return $this->steps;
    }

    /**
     * Every field in every step, so validation and dehydration see a wizard
     * as the flat form it is.
     *
     * @return list<Field>
     */
    public function fields(): array
    {
        $fields = [];

        foreach ($this->steps as $step) {
            $fields = [...$fields, ...$step->fields()];
        }

        return $fields;
    }

    /**
     * The field names in one step, by position.
     *
     * The same list the step serializes, so the server validates exactly
     * what the frontend showed.
     *
     * @return list<string>
     */
    public function fieldNamesForStep(int $step): array
    {
        $target = $this->steps[$step] ?? null;

        if ($target === null) {
            return [];
        }

        return array_map(
            static fn (Field $field): string => $field->getName(),
            $target->fields(),
        );
    }

    public function countSteps(): int
    {
        return count($this->steps);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(?Model $record, string $page): array
    {
        return [
            'component' => 'wizard',
            'submitLabel' => $this->submitLabel,
            'steps' => array_map(
                static fn (Step $step): array => $step->toArray($record, $page),
                $this->steps,
            ),
        ];
    }
}
