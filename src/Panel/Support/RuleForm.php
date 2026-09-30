<?php

declare(strict_types=1);

namespace PandaBear\Mlm\Panel\Support;

use PandaBear\Mlm\Models\PlanRule;
use PandaBear\Mlm\Planning\Rules\RuleDefinition;
use PandaPanel\Forms\Components\KeyValue;
use PandaPanel\Forms\Components\NumberInput;
use PandaPanel\Forms\Components\Repeater;
use PandaPanel\Forms\Components\Select;
use PandaPanel\Forms\Components\TagsInput;
use PandaPanel\Forms\Components\TextInput;
use PandaPanel\Forms\FormSchema;
use PandaPanel\Forms\Layouts\Section;

/**
 * A plan rule as a form: one group of conditions, all or any, each a
 * registered metric, its parameters, one of the language's operators, and
 * decimal operands. Everything submitted is data for `RuleDefinition` — no
 * expression, template or code is ever evaluated.
 *
 * Nested groups exist in the language but not in this form: a rule stored
 * with one can be removed and re-added, not changed here.
 */
final class RuleForm
{
    public static function schema(?PlanRule $rule = null): FormSchema
    {
        $definition = $rule?->definition->toArray();

        return FormSchema::make()->schema([
            Section::make(Display::section('rule'))->columns(2)->schema([
                ...($rule === null ? [TextInput::make('key')->label(Display::field('key'))->required()->maxLength(64)] : []),
                TextInput::make('name')->label(Display::field('name'))->required()->maxLength(255)->default($rule?->name),
                NumberInput::make('position')->label(Display::field('position'))->integer()->min(0)->default($rule?->position),
                Select::make('match')
                    ->label(Display::field('match'))
                    ->options(Options::combinators())
                    ->required()
                    ->default($definition['match'] ?? 'all'),
            ]),
            Repeater::make('conditions')
                ->label(Display::field('conditions'))
                ->minItems(1)
                ->schema([
                    Select::make('metric')->label(Display::field('metric'))->options(Options::metrics())->required(),
                    KeyValue::make('parameters')->label(Display::field('metric_parameters'))->helperText(__('mlm::mlm.helpers.metric_parameters')),
                    Select::make('operator')->label(Display::field('operator'))->options(Options::operators())->required(),
                    TagsInput::make('operands')->label(Display::field('operands'))->helperText(__('mlm::mlm.helpers.operands')),
                ])
                ->default(array_map(static fn (array $condition): array => [
                    'metric' => $condition['metric'],
                    'parameters' => array_map(static fn (mixed $value): string => is_scalar($value) ? (string) $value : (string) json_encode($value), $condition['parameters']),
                    'operator' => $condition['operator'],
                    'operands' => $condition['operands'],
                ], $definition['children'] ?? [])),
        ]);
    }

    /**
     * Whether this form can show the rule: one group of conditions only.
     */
    public static function isFlat(PlanRule $rule): bool
    {
        foreach ($rule->definition->toArray()['children'] as $child) {
            if (($child['type'] ?? null) !== 'condition') {
                return false;
            }
        }

        return true;
    }

    /**
     * The rule from what the form submitted, through the language's own
     * reader — which refuses anything the language does not have.
     *
     * A metric parameter written as a whole number is read as one: the form
     * sends text, and a depth is a number.
     *
     * @param  array<string, mixed>  $data
     */
    public static function definition(array $data): RuleDefinition
    {
        $children = [];

        foreach ((array) ($data['conditions'] ?? []) as $condition) {
            if (! is_array($condition)) {
                continue;
            }

            $parameters = [];

            foreach ((array) ($condition['parameters'] ?? []) as $key => $value) {
                $parameters[(string) $key] = is_string($value) && preg_match('/^-?[0-9]+$/', $value) === 1 ? (int) $value : $value;
            }

            $children[] = [
                'type' => 'condition',
                'metric' => $condition['metric'] ?? null,
                'parameters' => $parameters,
                'operator' => $condition['operator'] ?? null,
                'operands' => array_values(array_map(strval(...), (array) ($condition['operands'] ?? []))),
            ];
        }

        return RuleDefinition::fromArray([
            'type' => 'group',
            'match' => $data['match'] ?? null,
            'children' => $children,
        ]);
    }
}
