<?php

declare(strict_types=1);

namespace PandaBear\Mlm\Tests\Production;

use PandaPanel\Actions\Action;
use PandaPanel\Actions\ViewAction;
use PandaPanel\Contracts\PanelPlugin;
use PandaPanel\Core\Panel;
use PandaPanel\Core\PanelManager;
use PandaPanel\Forms\Components\CodeEditor;
use PandaPanel\Forms\Components\DateTimePicker;
use PandaPanel\Forms\Components\Field;
use PandaPanel\Forms\Components\KeyValue;
use PandaPanel\Forms\Components\NumberInput;
use PandaPanel\Forms\Components\Repeater;
use PandaPanel\Forms\Components\Select;
use PandaPanel\Forms\Components\TagsInput;
use PandaPanel\Forms\Components\Textarea;
use PandaPanel\Forms\Components\TextInput;
use PandaPanel\Forms\Enums\CalloutTone;
use PandaPanel\Forms\Enums\CodeLanguage;
use PandaPanel\Forms\Enums\ConditionOperator;
use PandaPanel\Forms\FormSchema;
use PandaPanel\Forms\Layouts\Callout;
use PandaPanel\Forms\Layouts\Section as FormSection;
use PandaPanel\Forms\Support\FormState;
use PandaPanel\Infolists\Components\BadgeEntry;
use PandaPanel\Infolists\Components\CodeEntry;
use PandaPanel\Infolists\Components\DateTimeEntry;
use PandaPanel\Infolists\Components\Entry;
use PandaPanel\Infolists\Components\KeyValueEntry;
use PandaPanel\Infolists\Components\TextEntry;
use PandaPanel\Infolists\InfolistSchema;
use PandaPanel\Infolists\Layouts\Section;
use PandaPanel\Pages\Page;
use PandaPanel\Plugins\PluginMetadata;
use PandaPanel\Resources\Pages\ListRecords;
use PandaPanel\Resources\Pages\ViewRecord;
use PandaPanel\Resources\RelationManager;
use PandaPanel\Resources\Resource;
use PandaPanel\Routing\PanelRouteRegistrar;
use PandaPanel\Support\NavigationItem;
use PandaPanel\Tables\ArrayTableData;
use PandaPanel\Tables\Columns\BadgeColumn;
use PandaPanel\Tables\Columns\Column;
use PandaPanel\Tables\Columns\DateTimeColumn;
use PandaPanel\Tables\Columns\NumberColumn;
use PandaPanel\Tables\Columns\TextColumn;
use PandaPanel\Tables\Enums\BadgeColor;
use PandaPanel\Tables\Enums\ColumnType;
use PandaPanel\Tables\Enums\SortDirection;
use PandaPanel\Tables\Filters\SelectFilter;
use PandaPanel\Tables\TableSchema;
use PandaPanel\Widgets\Enums\StatColor;
use PandaPanel\Widgets\StatsWidget;
use PandaPanel\Widgets\Support\Stat;
use PandaPanel\Widgets\TableWidget;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

/**
 * Every Panda Panel contract the plugin stands on (ADR-031): the classes,
 * methods and enum cases it calls, checked by reflection. A Panda Panel
 * release that removes or renames one fails here, by name, instead of
 * somewhere inside a request.
 *
 * Verified against the lowest version the constraint admits: the whole
 * Panel suite passes on chocoalano/panel v0.5.7 as on v0.5.8.
 */
final class PandaPanelContractTest extends TestCase
{
    /**
     * @var array<class-string, list<string>>
     */
    private const METHODS = [
        PanelPlugin::class => ['id', 'register', 'boot', 'metadata', 'publishes'],
        Panel::class => ['make', 'path', 'plugins', 'resources', 'pages', 'hasPlugin', 'plugin', 'getId'],
        PanelManager::class => ['register', 'setCurrentPanel', 'currentPanel', 'resources', 'pages'],
        PanelRouteRegistrar::class => ['register'],
        Resource::class => [
            'query', 'table', 'form', 'infolist', 'pages', 'relationManagers', 'navigationItem', 'defaultLabel', 'defaultPluralLabel',
            'recordTitle', 'url', 'slug', 'canViewAny', 'canView', 'canCreate', 'canEdit', 'canDelete', 'canDeleteAny', 'canRestore',
            'canForceDelete', 'canRestoreAny', 'canForceDeleteAny', 'tenantRelationship', 'configurationIn',
        ],
        RelationManager::class => [
            'table', 'query', 'relationForTable', 'title', 'key', 'canViewAny', 'canView', 'canCreate', 'canEdit', 'canDelete',
            'canRestore', 'canForceDelete', 'canAttach', 'canDetach', 'canAssociate', 'canDissociate',
        ],
        Page::class => ['title', 'subheading', 'navigationGroup', 'canAccess', 'widgets', 'filterSchema', 'url', 'slug', 'navigationItem'],
        Action::class => [
            'make', 'label', 'icon', 'action', 'tableAction', 'schema', 'visible', 'authorize', 'url', 'requiresConfirmation',
            'modalHeading', 'modalDescription', 'modalSubmitLabel', 'successMessage', 'databaseTransaction', 'hasDatabaseTransaction',
            'isAuthorizedFor', 'isVisibleFor', 'execute', 'executeWithoutRecord', 'getName', 'type', 'toArray',
        ],
        ViewAction::class => ['make'],
        TableSchema::class => [
            'make', 'columns', 'filters', 'callout', 'emptyState', 'headerActions', 'emptyStateActions', 'recordActions', 'defaultSort',
            'perPageOptions', 'defaultPerPage', 'getHeaderActions', 'getRecordActions', 'getEmptyStateActions', 'getBulkActions', 'getColumn',
        ],
        Column::class => ['make', 'label', 'sortable', 'searchable', 'formatUsing', 'placeholder', 'queryable', 'visible', 'tooltip', 'counts', 'applyQuery', 'toQueryConstraint', 'toCell', 'type'],
        TextColumn::class => ['limit', 'wrap'],
        BadgeColumn::class => ['colors', 'labels'],
        SelectFilter::class => ['make', 'options', 'column', 'label'],
        Callout::class => ['make', 'heading', 'tone'],
        InfolistSchema::class => ['make', 'schema', 'actions', 'allActions'],
        Section::class => ['make', 'schema', 'columns', 'description'],
        Entry::class => ['make', 'label', 'formatUsing', 'placeholder', 'visible', 'action', 'columnSpanFull', 'helperText'],
        BadgeEntry::class => ['colors'],
        CodeEntry::class => ['language'],
        FormSchema::class => ['make', 'schema'],
        FormSection::class => ['make', 'schema', 'columns', 'description'],
        Field::class => ['make', 'label', 'required', 'default', 'rules', 'helperText', 'visibleWhen', 'live'],
        TextInput::class => ['maxLength'],
        Textarea::class => ['maxLength'],
        NumberInput::class => ['integer', 'min', 'max'],
        DateTimePicker::class => ['seconds'],
        Select::class => ['options', 'optionsUsing', 'existsIn', 'searchable'],
        Repeater::class => ['schema', 'minItems'],
        CodeEditor::class => ['language'],
        FormState::class => ['get'],
        StatsWidget::class => ['stats', 'data', 'canView'],
        Stat::class => ['make', 'description', 'icon', 'color'],
        TableWidget::class => ['table', 'query', 'data', 'stateNamespace', 'canView'],
        ArrayTableData::class => ['make', 'paginate', 'rows', 'state', 'pagination'],
        PluginMetadata::class => ['__construct'],
    ];

    /**
     * Classes the plugin extends or instantiates.
     *
     * @var list<class-string>
     */
    private const CLASSES = [
        ListRecords::class, ViewRecord::class, DateTimeColumn::class, NumberColumn::class, DateTimeEntry::class, TextEntry::class,
        KeyValueEntry::class, KeyValue::class, TagsInput::class, NavigationItem::class,
    ];

    /**
     * @var array<class-string, list<string>>
     */
    private const CASES = [
        BadgeColor::class => ['Neutral', 'Success', 'Warning', 'Danger', 'Info'],
        CalloutTone::class => ['Info', 'Warning'],
        CodeLanguage::class => ['Json'],
        ConditionOperator::class => ['Equals', 'NotEquals', 'In', 'NotIn'],
        SortDirection::class => ['Ascending', 'Descending'],
        StatColor::class => ['Default', 'Warning'],
        ColumnType::class => ['Text'],
    ];

    public function test_every_method_the_plugin_calls_exists(): void
    {
        foreach (self::METHODS as $class => $methods) {
            $this->assertTrue(class_exists($class) || interface_exists($class), "Panda Panel no longer has [{$class}].");

            foreach ($methods as $method) {
                $this->assertTrue(method_exists($class, $method), "Panda Panel's [{$class}] no longer has [{$method}()].");
                $this->assertTrue((new ReflectionMethod($class, $method))->isPublic(), "[{$class}::{$method}()] is no longer public.");
            }
        }

        foreach (self::CLASSES as $class) {
            $this->assertTrue(class_exists($class), "Panda Panel no longer has [{$class}].");
        }
    }

    public function test_every_enum_case_the_plugin_names_exists(): void
    {
        foreach (self::CASES as $enum => $cases) {
            foreach ($cases as $case) {
                $this->assertTrue(defined("{$enum}::{$case}"), "Panda Panel's [{$enum}] no longer has [{$case}].");
            }
        }
    }

    /**
     * The shapes the plugin relies on beyond a method's name.
     */
    public function test_the_contract_shapes_the_plugin_relies_on(): void
    {
        // A plugin implements the contract; it does not extend a base class.
        $this->assertTrue((new ReflectionClass(PanelPlugin::class))->isInterface());

        // The navigation item is rebuilt with a translated group by name.
        $parameters = array_map(static fn ($parameter): string => $parameter->getName(), (new ReflectionMethod(NavigationItem::class, '__construct'))->getParameters());
        $this->assertSame(['label', 'href', 'icon', 'badge', 'active', 'sort', 'group', 'children', 'fullPage', 'activeIcon'], $parameters);

        // Plugin metadata is named by these arguments.
        $metadata = array_map(static fn ($parameter): string => $parameter->getName(), (new ReflectionMethod(PluginMetadata::class, '__construct'))->getParameters());
        $this->assertContains('requiresPanel', $metadata);
        $this->assertContains('package', $metadata);

        // A domain action opts out of the panel's own transaction: the
        // services own theirs, and the period calculator refuses a caller's.
        $action = Action::make('probe')->databaseTransaction(false);
        $this->assertFalse($action->hasDatabaseTransaction());
    }
}
