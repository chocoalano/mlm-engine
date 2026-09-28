/**
 * Mirrors the PHP form schema.
 *
 * Components are a discriminated union on `component`, and fields a further
 * union on `type`. Renderers switch with an exhaustive check, so a PHP field
 * type without a Vue renderer is a compile error rather than a missing
 * input.
 */
import type { BadgeColorName } from '@/panel/palette';

export type { BadgeColorName };

export interface SelectOption {
    value: string;
    label: string;
}

/** An option that can also carry a line of explanation under its label. */
export interface DescribedOption extends SelectOption {
    description: string | null;
}

export interface ColoredOption extends SelectOption {
    color: BadgeColorName;
    icon: string | null;
}

/**
 * How one field's value is compared against another's.
 *
 * A closed set of *descriptions*, never code: the server says what to
 * compare and this bundle knows how. See `PandaPanel\Forms\Enums\
 * ConditionOperator` for why it is shaped this way.
 */
export type ConditionOperator =
    | 'equals'
    | 'not_equals'
    | 'in'
    | 'not_in'
    | 'filled'
    | 'blank'
    | 'greater_than'
    | 'less_than'
    | 'truthy'
    | 'falsy';

export interface ConditionDefinition {
    field: string;
    operator: ConditionOperator;
    value: unknown;
}

export interface FieldConditions {
    visibleWhen: ConditionDefinition[];
    hiddenWhen: ConditionDefinition[];
}

/** When the server wants to be told this field changed, and how eagerly. */
export interface LiveDefinition {
    onBlur: boolean;
    debounce: number;
}

/**
 * The subset of the server's rules a browser can honestly check.
 *
 * Anything needing the database is deliberately absent — the server is still
 * the authority, and these only save a round trip.
 */
export interface ValidationHints {
    required?: boolean;
    email?: boolean;
    numeric?: boolean;
    url?: boolean;
    min?: number;
    max?: number;
    confirmed?: boolean;
}

interface BaseFieldDefinition {
    component: 'field';
    name: string;
    label: string;
    value: unknown;
    placeholder: string | null;
    helperText: string | null;
    required: boolean;
    disabled: boolean;
    /** Renders the label beside the control rather than above it. */
    inlineLabel: boolean;
    /**
     * Columns of the container this field takes, or the whole row.
     *
     * `'full'` is resolved against the container that draws it, because the
     * number that means "all of them" is the container's to know.
     */
    columnSpan: number | 'full';
    conditions: FieldConditions;
    live: LiveDefinition | null;
    validation: ValidationHints;
}

export interface TextFieldDefinition extends BaseFieldDefinition {
    type: 'text';
    inputType: 'text' | 'email';
    maxLength: number | null;
}

export interface TextareaFieldDefinition extends BaseFieldDefinition {
    type: 'textarea';
    rows: number;
    maxLength: number | null;
}

export interface PasswordFieldDefinition extends BaseFieldDefinition {
    type: 'password';
    revealable: boolean;
    confirmed: boolean;
}

export interface NumberFieldDefinition extends BaseFieldDefinition {
    type: 'number';
    min: number | null;
    max: number | null;
    step: number | null;
}

/**
 * An amount of money. The value is a plain decimal string — `12500.50` — in
 * both directions, never a float and never the formatted text on screen.
 */
export interface MoneyFieldDefinition extends BaseFieldDefinition {
    type: 'money';
    /** ISO 4217 code. */
    currency: string;
    /** Digits after the decimal separator; also what validation allows. */
    decimals: number;
    /** BCP 47 tag, or null to follow the panel's locale. */
    locale: string | null;
    min: number | null;
    max: number | null;
}

export interface HiddenFieldDefinition extends BaseFieldDefinition {
    type: 'hidden';
}

export interface CheckboxFieldDefinition extends BaseFieldDefinition {
    type: 'checkbox';
}

export interface ToggleFieldDefinition extends BaseFieldDefinition {
    type: 'toggle';
}

export interface SelectFieldDefinition extends BaseFieldDefinition {
    type: 'select';
    options: SelectOption[];
    searchable: boolean;
    /** A many-to-many relation selects a set, so its value is an array. */
    multiple: boolean;
    usesRelationship: boolean;
    /**
     * Whether the server resolves this field's options from the rest of the
     * form — the employees in the chosen department, the shifts a chosen site
     * runs.
     *
     * The field sends the form's values with its searches, and throws away
     * what it has cached whenever the form changes underneath it: a dependent
     * list that went on answering for the previous parent is worse than one
     * that is briefly empty.
     */
    dependentOptions: boolean;
}

export interface DateFieldDefinition extends BaseFieldDefinition {
    type: 'date';
    minDate: string | null;
    maxDate: string | null;
}

export interface DateTimeFieldDefinition extends BaseFieldDefinition {
    type: 'datetime';
    minDate: string | null;
    maxDate: string | null;
    seconds: boolean;
}

export interface TimeFieldDefinition extends BaseFieldDefinition {
    type: 'time';
    seconds: boolean;
}

export interface RadioFieldDefinition extends BaseFieldDefinition {
    type: 'radio';
    options: DescribedOption[];
    inline: boolean;
}

export interface CheckboxListFieldDefinition extends BaseFieldDefinition {
    type: 'checkbox_list';
    options: DescribedOption[];
    columns: number;
    bulkToggleable: boolean;
}

export interface ToggleButtonsFieldDefinition extends BaseFieldDefinition {
    type: 'toggle_buttons';
    options: ColoredOption[];
    multiple: boolean;
    inline: boolean;
}

export interface ColorPickerFieldDefinition extends BaseFieldDefinition {
    type: 'color_picker';
    swatches: string[];
}

export interface SliderFieldDefinition extends BaseFieldDefinition {
    type: 'slider';
    min: number;
    max: number;
    step: number;
    showValue: boolean;
}

export interface TagsInputFieldDefinition extends BaseFieldDefinition {
    type: 'tags_input';
    suggestions: string[];
    maxTags: number | null;
    maxLength: number | null;
    separator: string | null;
}

export interface KeyValueFieldDefinition extends BaseFieldDefinition {
    type: 'key_value';
    keyLabel: string;
    valueLabel: string;
    maxPairs: number | null;
    addable: boolean;
    deletable: boolean;
    editableKeys: boolean;
}

export interface RichEditorFieldDefinition extends BaseFieldDefinition {
    type: 'rich_editor';
    toolbar: string[];
    maxLength: number | null;
}

export interface MarkdownEditorFieldDefinition extends BaseFieldDefinition {
    type: 'markdown_editor';
    toolbar: string[];
    maxLength: number | null;
    rows: number;
}

export type CodeLanguage =
    | 'plain'
    | 'json'
    | 'html'
    | 'css'
    | 'javascript'
    | 'php'
    | 'sql'
    | 'yaml'
    | 'markdown';

export interface CodeEditorFieldDefinition extends BaseFieldDefinition {
    type: 'code_editor';
    language: CodeLanguage;
    rows: number;
    maxLength: number | null;
}

export interface FileUploadFieldDefinition extends BaseFieldDefinition {
    type: 'file_upload';
    multiple: boolean;
    /** Kilobytes, matching Laravel's `max:` rule. */
    maxSize: number;
    maxFiles: number | null;
    acceptedTypes: string[];
    image: boolean;
    /** Null when the disk serves no public URL; the name is shown instead. */
    previewBase: string | null;
}

export interface RepeaterFieldDefinition extends BaseFieldDefinition {
    type: 'repeater';
    schema: FormComponentDefinition[];
    minItems: number | null;
    maxItems: number | null;
    reorderable: boolean;
    collapsible: boolean;
    addable: boolean;
    deletable: boolean;
    addLabel: string;
    columns: number;
    /** One label per current entry, or empty when none were declared. */
    itemLabels: string[];
    emptyItem: Record<string, unknown>;
}

export interface BlockDefinition {
    name: string;
    label: string;
    icon: string | null;
    schema: FormComponentDefinition[];
    emptyData: Record<string, unknown>;
}

export interface BuilderFieldDefinition extends BaseFieldDefinition {
    type: 'builder';
    blocks: BlockDefinition[];
    minItems: number | null;
    maxItems: number | null;
    reorderable: boolean;
    collapsible: boolean;
    addLabel: string;
}

export interface CustomFieldDefinition extends BaseFieldDefinition {
    type: 'custom';
    componentName: string;
    config: Record<string, unknown>;
}

export type FieldDefinition =
    | TextFieldDefinition
    | TextareaFieldDefinition
    | PasswordFieldDefinition
    | NumberFieldDefinition
    | MoneyFieldDefinition
    | HiddenFieldDefinition
    | CheckboxFieldDefinition
    | ToggleFieldDefinition
    | SelectFieldDefinition
    | DateFieldDefinition
    | DateTimeFieldDefinition
    | TimeFieldDefinition
    | RadioFieldDefinition
    | CheckboxListFieldDefinition
    | ToggleButtonsFieldDefinition
    | ColorPickerFieldDefinition
    | SliderFieldDefinition
    | TagsInputFieldDefinition
    | KeyValueFieldDefinition
    | RichEditorFieldDefinition
    | MarkdownEditorFieldDefinition
    | CodeEditorFieldDefinition
    | FileUploadFieldDefinition
    | RepeaterFieldDefinition
    | BuilderFieldDefinition
    | CustomFieldDefinition;

export interface SectionDefinition {
    component: 'section';
    heading: string;
    description: string | null;
    columns: number;
    collapsible: boolean;
    schema: FormComponentDefinition[];
}

export interface GridDefinition {
    component: 'grid';
    columns: number;
    schema: FormComponentDefinition[];
}

/**
 * Fields belonging to a single related record, edited inside the owner's
 * form. Its children carry dotted names (`profile.bio`) which the submit
 * expands into nested data and the server validates as nested keys.
 */
export interface RelationshipDefinition {
    component: 'relationship';
    relation: string;
    heading: string;
    description: string | null;
    columns: number;
    schema: FormComponentDefinition[];
}

export interface StepDefinition {
    component: 'step';
    label: string;
    description: string | null;
    icon: string | null;
    columns: number;
    schema: FormComponentDefinition[];
    /** Field names in this step, so an error can be traced back to it. */
    fields: string[];
}

export interface WizardDefinition {
    component: 'wizard';
    submitLabel: string;
    steps: StepDefinition[];
}

export interface TabDefinition {
    component: 'tab';
    label: string;
    key: string;
    icon: string | null;
    badge: string | null;
    columns: number;
    schema: FormComponentDefinition[];
    /** The names in this tab, so a rejected field can open the one holding it. */
    fields: string[];
}

export interface TabsDefinition {
    component: 'tabs';
    persistTab: boolean;
    tabs: TabDefinition[];
}

export type CalloutTone = 'info' | 'success' | 'warning' | 'danger';

export interface CalloutDefinition {
    component: 'callout';
    body: string | null;
    heading: string | null;
    tone: CalloutTone;
    icon: string | null;
    schema: FormComponentDefinition[];
}

export interface EmptyStateDefinition {
    component: 'empty-state';
    heading: string;
    description: string | null;
    icon: string | null;
}

export interface CustomComponentDefinition {
    component: 'custom';
    componentName: string;
    config: Record<string, unknown>;
    schema: FormComponentDefinition[];
}

export interface PrimeTextDefinition {
    component: 'prime-text';
    content: string | null;
    color: BadgeColorName | null;
    icon: string | null;
    small: boolean;
}

export interface PrimeIconDefinition {
    component: 'prime-icon';
    icon: string | null;
    color: BadgeColorName | null;
    label: string | null;
}

export interface PrimeImageDefinition {
    component: 'prime-image';
    url: string | null;
    alt: string;
    width: number | null;
    rounded: boolean;
}

export type FormComponentDefinition =
    | FieldDefinition
    | SectionDefinition
    | GridDefinition
    | RelationshipDefinition
    | WizardDefinition
    | TabsDefinition
    | CalloutDefinition
    | EmptyStateDefinition
    | CustomComponentDefinition
    | PrimeTextDefinition
    | PrimeIconDefinition
    | PrimeImageDefinition;

export interface FormDefinition {
    columns: number;
    schema: FormComponentDefinition[];
}

/**
 * What a control can hold and what Inertia can send.
 *
 * Form state is JSON, and now genuinely so: a repeater holds a list of
 * entries and a key-value field holds a map, neither of which fits a flat
 * scalar union. Values are still normalized once on the way in — anything
 * that is not JSON becomes null there — rather than being asserted at every
 * use.
 */
export type FormValue =
    | string
    | number
    | boolean
    | null
    | FormValue[]
    | { [key: string]: FormValue };

/** The form's working values, keyed by field name. */
export type FormValues = Record<string, FormValue>;
