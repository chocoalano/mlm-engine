<?php

declare(strict_types=1);

namespace PandaBear\Mlm\Panel\Support;

use Illuminate\Validation\ValidationException;
use PandaBear\Mlm\Binary\Pairing\BinaryPairingFixedStrategy;
use PandaBear\Mlm\Binary\Pairing\BinaryPairingParameters;
use PandaBear\Mlm\Binary\Pairing\BinaryPairingProportionalStrategy;
use PandaBear\Mlm\Commission\CommissionComponentDriver;
use PandaBear\Mlm\Commission\Strategies\DirectSponsorFixedParameters;
use PandaBear\Mlm\Commission\Strategies\DirectSponsorFixedStrategy;
use PandaBear\Mlm\Commission\Strategies\DirectSponsorProportionalParameters;
use PandaBear\Mlm\Commission\Strategies\DirectSponsorProportionalStrategy;
use PandaBear\Mlm\Commission\Strategies\MatrixFixedParameters;
use PandaBear\Mlm\Commission\Strategies\MatrixFixedStrategy;
use PandaBear\Mlm\Commission\Strategies\MatrixProportionalParameters;
use PandaBear\Mlm\Commission\Strategies\MatrixProportionalStrategy;
use PandaBear\Mlm\Commission\Strategies\UnilevelFixedParameters;
use PandaBear\Mlm\Commission\Strategies\UnilevelFixedStrategy;
use PandaBear\Mlm\Commission\Strategies\UnilevelProportionalParameters;
use PandaBear\Mlm\Commission\Strategies\UnilevelProportionalStrategy;
use PandaBear\Mlm\Models\PlanComponent;
use PandaBear\Mlm\Models\PlanVersion;
use PandaPanel\Forms\Components\CodeEditor;
use PandaPanel\Forms\Components\Field;
use PandaPanel\Forms\Components\NumberInput;
use PandaPanel\Forms\Components\Repeater;
use PandaPanel\Forms\Components\Select;
use PandaPanel\Forms\Components\TextInput;
use PandaPanel\Forms\Enums\CodeLanguage;
use PandaPanel\Forms\Enums\ConditionOperator;
use PandaPanel\Forms\FormSchema;
use PandaPanel\Forms\Layouts\Section;

/**
 * A plan component as a form, and the form back as the component's inert
 * parameters.
 *
 * Drivers and strategies are chosen from their registries, never typed. A
 * built-in strategy's own parameters are fields — exactly the fields its
 * parameter class names, nothing more — and anything else is a JSON object,
 * decoded and handed over as data. Whether the parameters are valid is not
 * decided here: `PlanDefinitionValidator` decides that when the version is
 * validated.
 */
final class ComponentForm
{
    /**
     * Each built-in strategy's own parameters, as its parameter class names
     * them.
     *
     * @var array<string, list<string>>
     */
    private const FIELDS = [
        DirectSponsorFixedStrategy::KEY => DirectSponsorFixedParameters::FIELDS,
        DirectSponsorProportionalStrategy::KEY => DirectSponsorProportionalParameters::FIELDS,
        UnilevelFixedStrategy::KEY => UnilevelFixedParameters::FIELDS,
        UnilevelProportionalStrategy::KEY => UnilevelProportionalParameters::FIELDS,
        MatrixFixedStrategy::KEY => MatrixFixedParameters::FIELDS,
        MatrixProportionalStrategy::KEY => MatrixProportionalParameters::FIELDS,
        BinaryPairingFixedStrategy::KEY => BinaryPairingParameters::FIXED,
        BinaryPairingProportionalStrategy::KEY => BinaryPairingParameters::PROPORTIONAL,
    ];

    /**
     * What each level of a levelled strategy awards per depth.
     *
     * @var array<string, string>
     */
    private const LEVEL_AMOUNT = [
        UnilevelFixedStrategy::KEY => 'amount',
        MatrixFixedStrategy::KEY => 'amount',
        UnilevelProportionalStrategy::KEY => 'unit_amount',
        MatrixProportionalStrategy::KEY => 'unit_amount',
    ];

    private const SCALARS = ['volume_type', 'source_type', 'minimum_quantity', 'amount', 'unit_amount', 'pair_quantity', 'amount_per_pair'];

    public static function schema(PlanVersion $version, ?PlanComponent $component = null): FormSchema
    {
        $stored = $component?->parameters ?? [];
        $commission = $component === null || $component->driver === CommissionComponentDriver::KEY;
        $strategy = $commission ? ($stored['strategy'] ?? null) : null;
        $own = is_array($stored['parameters'] ?? null) ? $stored['parameters'] : [];

        $fields = [
            ...($component === null ? [TextInput::make('key')->label(Display::field('key'))->required()->maxLength(64)] : []),
            TextInput::make('name')->label(Display::field('name'))->required()->maxLength(255)->default($component?->name),
            ...($component === null ? [Select::make('driver')->label(Display::field('driver'))->options(Options::drivers())->required()->default(CommissionComponentDriver::KEY)] : []),
            NumberInput::make('position')->label(Display::field('position'))->integer()->min(0)->default($component?->position),
        ];

        $commissionFields = [
            Select::make('strategy')->label(Display::field('strategy'))->options(Options::strategies())->default($strategy),
            TextInput::make('currency')->label(Display::field('currency'))->maxLength(3)->default($stored['currency'] ?? null),
            Select::make('source_account')
                ->label(Display::field('source_account'))
                ->helperText(__('mlm::mlm.helpers.source_account'))
                ->options(Options::systemAccounts((string) $version->plan->program_id, value: 'key'))
                ->default($stored['source_account'] ?? null),
        ];

        foreach (self::SCALARS as $name) {
            $commissionFields[] = TextInput::make($name)
                ->label(Display::field($name))
                ->default(isset($own[$name]) && is_scalar($own[$name]) ? (string) $own[$name] : null)
                ->visibleWhen('strategy', ConditionOperator::In, self::strategiesWith($name));
        }

        $commissionFields[] = Select::make('rounding')
            ->label(Display::field('rounding'))
            ->options(Options::roundingModes())
            ->default($own['rounding'] ?? null)
            ->visibleWhen('strategy', ConditionOperator::In, self::strategiesWith('rounding'));

        $commissionFields[] = Repeater::make('levels')
            ->label(Display::field('levels'))
            ->schema([
                NumberInput::make('depth')->label(Display::field('depth'))->integer()->min(1)->required(),
                TextInput::make('value')->label(Display::field('level_amount'))->required(),
            ])
            ->default(self::levelsOf($strategy, $own))
            ->visibleWhen('strategy', ConditionOperator::In, array_keys(self::LEVEL_AMOUNT));

        $commissionFields[] = CodeEditor::make('strategy_parameters')
            ->label(Display::field('parameters'))
            ->helperText(__('mlm::mlm.helpers.json_object'))
            ->language(CodeLanguage::Json)
            ->default($strategy !== null && ! isset(self::FIELDS[$strategy]) ? (string) Display::json($own ?: new \stdClass) : null)
            ->visibleWhen('strategy', ConditionOperator::NotIn, array_keys(self::FIELDS));

        $json = CodeEditor::make('parameters_json')
            ->label(Display::field('parameters'))
            ->helperText(__('mlm::mlm.helpers.json_object'))
            ->language(CodeLanguage::Json)
            ->default((string) Display::json($stored ?: new \stdClass));

        // A new component chooses its driver, so both sets of fields are
        // there and the driver decides which is shown — and which is read.
        if ($component === null) {
            $commissionFields = array_map(
                static fn (Field $field): Field => $field->visibleWhen('driver', ConditionOperator::Equals, CommissionComponentDriver::KEY),
                $commissionFields,
            );
            $json->visibleWhen('driver', ConditionOperator::NotEquals, CommissionComponentDriver::KEY);
        }

        return FormSchema::make()->schema([
            Section::make(Display::section('component'))->columns(2)->schema($fields),
            ...($commission ? [
                Section::make(Display::section('commission'))
                    ->description(__('mlm::mlm.helpers.commission_component'))
                    ->columns(2)
                    ->schema($commissionFields),
            ] : []),
            ...($component === null || ! $commission ? [Section::make(Display::section('parameters'))->schema([$json])] : []),
        ]);
    }

    /**
     * The component's parameters from what the form submitted: data only.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function parameters(string $driver, array $data): array
    {
        if ($driver !== CommissionComponentDriver::KEY) {
            return self::object($data['parameters_json'] ?? null, 'parameters_json');
        }

        $strategy = (string) ($data['strategy'] ?? '');

        return [
            'strategy' => $strategy,
            'currency' => (string) ($data['currency'] ?? ''),
            'source_account' => (string) ($data['source_account'] ?? ''),
            'parameters' => isset(self::FIELDS[$strategy])
                ? self::strategyParameters($strategy, $data)
                : self::object($data['strategy_parameters'] ?? null, 'strategy_parameters'),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private static function strategyParameters(string $strategy, array $data): array
    {
        $parameters = [];

        foreach (self::FIELDS[$strategy] as $field) {
            if ($field === 'levels') {
                $parameters['levels'] = array_values(array_map(static fn (array $level): array => [
                    'depth' => (int) $level['depth'],
                    self::LEVEL_AMOUNT[$strategy] => (string) $level['value'],
                ], array_filter((array) ($data['levels'] ?? []), is_array(...))));

                continue;
            }

            // Left out when blank, so the validator names it as missing
            // rather than as the wrong kind of value.
            if (isset($data[$field]) && $data[$field] !== '') {
                $parameters[$field] = (string) $data[$field];
            }
        }

        return $parameters;
    }

    /**
     * @param  array<string, mixed>  $own
     * @return list<array{depth: int, value: string}>
     */
    private static function levelsOf(?string $strategy, array $own): array
    {
        if ($strategy === null || ! isset(self::LEVEL_AMOUNT[$strategy]) || ! is_array($own['levels'] ?? null)) {
            return [];
        }

        return array_values(array_map(
            static fn (mixed $level): array => ['depth' => (int) ($level['depth'] ?? 0), 'value' => (string) ($level[self::LEVEL_AMOUNT[$strategy]] ?? '')],
            array_filter($own['levels'], is_array(...)),
        ));
    }

    /**
     * @return list<string>
     */
    private static function strategiesWith(string $field): array
    {
        return array_keys(array_filter(self::FIELDS, static fn (array $fields): bool => in_array($field, $fields, true)));
    }

    /**
     * A JSON object, decoded — nothing else is accepted, and nothing in it
     * is ever run.
     *
     * @return array<string, mixed>
     */
    private static function object(mixed $json, string $field): array
    {
        $decoded = is_string($json) && trim($json) !== '' ? json_decode($json, true) : [];

        if (! is_array($decoded) || ($decoded !== [] && array_is_list($decoded))) {
            throw ValidationException::withMessages([$field => __('mlm::mlm.helpers.json_object')]);
        }

        return $decoded;
    }
}
