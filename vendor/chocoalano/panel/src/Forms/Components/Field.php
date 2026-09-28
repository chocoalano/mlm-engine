<?php

declare(strict_types=1);

namespace PandaPanel\Forms\Components;

use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Illuminate\Support\Traits\Conditionable;
use PandaPanel\Exceptions\PanelSchemaException;
use PandaPanel\Forms\Enums\ConditionOperator;
use PandaPanel\Forms\Enums\FieldType;
use PandaPanel\Forms\Support\CallbackParameters;
use PandaPanel\Forms\Support\Condition;
use PandaPanel\Forms\Support\FormState;
use PandaPanel\Support\Label;

/**
 * Base for every form field.
 *
 * A field declares three separable things: how it renders, what Laravel
 * rules validate it, and whether its value is persisted. Keeping them apart
 * is what lets a password field render as optional on edit, still validate
 * when filled, and be dropped from the payload when blank, without any of
 * those three leaking into the others.
 *
 * Validation rules are the server's. The `required` flag is a UX marker only;
 * removing it in the browser changes nothing.
 */
abstract class Field extends FormComponent
{
    /** Gives every field `when()` / `unless()` for page-dependent configuration. */
    use Conditionable;

    protected ?string $label = null;

    protected ?string $placeholder = null;

    protected ?string $helperText = null;

    protected bool $required = false;

    protected bool $disabled = false;

    /**
     * How many of the container's columns this field takes.
     *
     * `'full'` rather than a number for the whole row, because the number
     * that means "all of them" depends on the container: a field written
     * `columnSpan(2)` inside a section is full width until somebody makes
     * that section three columns, and then it is silently two thirds.
     *
     * @var int|'full'
     */
    protected int|string $columnSpan = 1;

    protected mixed $default = null;

    /** @var list<mixed> */
    protected array $rules = [];

    /** @var (Closure(?Model): list<mixed>)|null */
    protected ?Closure $rulesUsing = null;

    /** @var list<string> */
    protected array $hiddenOn = [];

    /** @var list<string>|null */
    protected ?array $visibleOn = null;

    /** @var list<string> */
    protected array $disabledOn = [];

    /**
     * Pages the field is immutable on — shown, not editable, and not writable
     * from the request whatever arrives. See `immutableOn()`.
     *
     * @var list<string>
     */
    protected array $immutableOn = [];

    /** Immutable everywhere. See `immutable()`. */
    protected bool $immutable = false;

    /**
     * Pages the field is required on, when that is not every page.
     *
     * Null means "whatever `required()` said, everywhere", which is what every
     * field written before this existed means.
     *
     * @var list<string>|null
     */
    protected ?array $requiredOn = null;

    /**
     * Which page the schema is currently building for.
     *
     * Set by the schema rather than passed to every method that needs it:
     * `validationRules()` is public and is called from four places, and adding
     * a parameter to it would break every caller to answer a question the
     * schema already knows the answer to.
     */
    protected ?string $pageContext = null;

    /**
     * The form state the schema is currently being built against.
     *
     * Read-only as far as a visibility closure is concerned: it decides
     * whether a field exists, and a field that rewrote its siblings while
     * being asked that would be answering a different question.
     */
    protected ?FormState $stateContext = null;

    /** @var (Closure(?Model): bool)|null */
    protected ?Closure $hiddenUsing = null;

    /** @var (Closure(?Model): bool)|null */
    protected ?Closure $visibleUsing = null;

    /** @var (Closure(?Model): bool)|null */
    protected ?Closure $disabledUsing = null;

    /**
     * Conditions the browser re-evaluates as the user types.
     *
     * Kept apart from the closures above, which run once on the server and
     * cannot react. Both exist because they answer different questions: "is
     * this field ever shown to this user" is the server's, "is it shown right
     * now given what has been typed" is the browser's.
     *
     * @var list<Condition>
     */
    protected array $visibleWhen = [];

    /** @var list<Condition> */
    protected array $hiddenWhen = [];

    /**
     * Whether changing this field asks the server to rebuild the form.
     *
     * Off by default: a round trip per keystroke is the wrong default, and a
     * field only needs it when something else depends on its value in a way
     * the declarative conditions cannot express.
     */
    protected bool $live = false;

    protected bool $liveOnBlur = false;

    protected int $liveDebounce = 500;

    protected bool $inlineLabel = false;

    /** @var (Closure(mixed, ?Model): mixed)|null */
    protected ?Closure $afterStateHydrated = null;

    /** @var (Closure(mixed, mixed, ?Model): void)|null */
    protected ?Closure $afterStateUpdated = null;

    /** @var (Closure(mixed, ?Model): mixed)|null */
    protected ?Closure $dehydrateStateUsing = null;

    /** @var (Closure(?Model): bool)|bool|null */
    protected Closure|bool|null $dehydrated = null;

    protected ?string $dehydrateTo = null;

    /**
     * The relation this field belongs to, when it sits inside a
     * `Relationship` group.
     *
     * It namespaces the field on the wire and in the rules (`profile.bio`)
     * without touching `$name`, which stays the attribute on the related
     * record — the two are different questions and conflating them would make
     * `formValue()` read `profile.bio` off a model that only has `bio`.
     */
    protected string $namePrefix = '';

    /** @var (Closure(mixed): bool)|null */
    protected ?Closure $dehydrateWhen = null;

    /** @var (Closure(mixed, ?Model): mixed)|null */
    protected ?Closure $mutateUsing = null;

    /** @var (Closure(mixed, ?Model): mixed)|null */
    protected ?Closure $formatUsing = null;

    final public function __construct(protected readonly string $name)
    {
        if (trim($name) === '') {
            throw PanelSchemaException::emptyName('field');
        }
    }

    public static function make(string $name): static
    {
        return new static($name);
    }

    abstract public function type(): FieldType;

    public function label(string $label): static
    {
        $this->label = $label;

        return $this;
    }

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

    /**
     * Marks the field required for the user and adds the `required` rule.
     * Pass `false` to mark it optional and add `nullable` instead.
     */
    public function required(bool $required = true): static
    {
        $this->required = $required;

        return $this;
    }

    public function disabled(bool $disabled = true): static
    {
        $this->disabled = $disabled;

        return $this;
    }

    public function columnSpan(int $span): static
    {
        $this->columnSpan = max(1, $span);

        return $this;
    }

    /**
     * The whole row, whatever the container turns out to be divided into.
     *
     * Resolved against the container that draws it rather than here, so a
     * section changed from two columns to three keeps this field full width
     * instead of leaving a gap nobody edited.
     */
    public function columnSpanFull(): static
    {
        $this->columnSpan = 'full';

        return $this;
    }

    public function default(mixed $default): static
    {
        $this->default = $default;

        return $this;
    }

    /**
     * Additional Laravel rules. These are the source of truth; the frontend
     * receives only the hints in `toArray()`.
     *
     * @param  list<mixed>  $rules
     */
    public function rules(array $rules): static
    {
        $this->rules = $rules;

        return $this;
    }

    /**
     * Rules that need the record, such as a unique check that ignores the
     * record being edited.
     *
     * @param  Closure(?Model): list<mixed>  $callback
     */
    public function rulesUsing(Closure $callback): static
    {
        $this->rulesUsing = $callback;

        return $this;
    }

    /**
     * Hides the field on the named pages, for example `['edit']`. A hidden
     * field is neither rendered nor validated nor persisted.
     *
     * @param  list<string>  $pages
     */
    public function hiddenOn(array $pages): static
    {
        $this->hiddenOn = $pages;

        return $this;
    }

    /**
     * Shows the field *only* on the named pages.
     *
     * The inverse of `hiddenOn()` and worth having separately: a field that
     * belongs to creation alone is clearer as `visibleOn(['create'])` than as
     * a list of every other page.
     *
     * @param  list<string>  $pages
     */
    public function visibleOn(array $pages): static
    {
        $this->visibleOn = $pages;

        return $this;
    }

    /**
     * Renders the field read-only on the named pages.
     *
     * Different from hiding it: a disabled field is still shown, still says
     * what the value is, and is still not persisted from the browser.
     *
     * @param  list<string>  $pages
     */
    public function disabledOn(array $pages): static
    {
        $this->disabledOn = $pages;

        return $this;
    }

    /**
     * Shown, not editable, and not writable — on every page.
     *
     * `disabled()` is presentation only, and deliberately so: it describes a
     * browser state, and the browser is not where write authority lives. A
     * disabled control simply is not submitted, so nothing arrives; a request
     * that was not made by that browser can still carry the field, and
     * `dehydrate()` will pass it through. That is documented, and it is why
     * `dehydrated(false)` exists.
     *
     * The trouble is that a field which must not be edited nearly always
     * needs both, and the two are declared separately:
     *
     *     ->disabledOn(['edit'])
     *     ->dehydrated(fn () => ...)     // the half that is easy to forget
     *
     * Forget the second and the form looks locked while the column is wide
     * open — which is exactly the shape a crafted request is looking for.
     *
     * This says both once. The field renders disabled *and* the server
     * refuses to write it, so the guard cannot be half-declared.
     *
     * It also stops being required, because a value the server will not
     * accept cannot sensibly be demanded from the client — see `isRequired()`.
     */
    public function immutable(bool $immutable = true): static
    {
        $this->immutable = $immutable;

        return $this;
    }

    /**
     * The same, on the named pages only.
     *
     *     TextInput::make('employee_code')->immutableOn(['edit'])
     *
     * Set once at creation, locked afterwards. On every other page the field
     * behaves normally, so a create form still writes it.
     *
     * @param  list<string>  $pages
     */
    public function immutableOn(array $pages): static
    {
        $this->immutableOn = $pages;

        return $this;
    }

    /**
     * Whether the field refuses writes on this page.
     *
     * The page defaults to whichever one the schema is building — see
     * `onPage()` — so `dehydrate()` and the serializer agree without either
     * having to be told.
     */
    public function isImmutableOn(?string $page = null): bool
    {
        if ($this->immutable) {
            return true;
        }

        return in_array($page ?? $this->pageContext ?? 'create', $this->immutableOn, true);
    }

    /**
     * Requires the field on the named pages, and only those.
     *
     * The other half of `disabledOn()`, and the pair is what makes a
     * write-once field expressible without contradicting itself:
     *
     *     ->requiredOn(['create'])
     *     ->disabledOn(['edit'])
     *     ->dehydrated(fn (?Model $record) => $record === null)
     *
     * Written as `->required()->disabledOn(['edit'])` the same field asks for
     * a value on the edit page and gives the browser no way to send one, so
     * an unrelated edit fails on a greyed-out box — see
     * `FormSchema::reportImpossibleRequirements()`.
     *
     * @param  list<string>  $pages
     */
    public function requiredOn(array $pages): static
    {
        $this->requiredOn = $pages;
        $this->required = true;

        return $this;
    }

    /**
     * Which page the schema is building. Called by `FormSchema`.
     */
    public function onPage(?string $page): static
    {
        $this->pageContext = $page;

        return $this;
    }

    /**
     * Whether a value is demanded, on the page being built or a named one.
     */
    public function isRequired(?string $page = null): bool
    {
        // A value the server refuses to accept cannot be demanded from the
        // client. Without this, `immutableOn(['edit'])->required()` would fail
        // every edit on a field nobody can type into — the contradiction
        // `FormSchema::reportImpossibleRequirements()` exists to catch.
        if ($this->isImmutableOn($page)) {
            return false;
        }

        if (! $this->required) {
            return false;
        }

        if ($this->requiredOn === null) {
            return true;
        }

        return in_array($page ?? $this->pageContext ?? 'create', $this->requiredOn, true);
    }

    /**
     * Hides the field outright, optionally per record or per form state.
     *
     * Two ways to react to what is being typed, and they are not
     * interchangeable. `hiddenWhen()` describes a comparison the browser
     * re-evaluates on every keystroke, costing no request — reach for it
     * first, and for anything a comparison can express it is the right answer.
     *
     * This one runs on the server, so it can consult anything the server
     * knows: a policy, a setting, another table. That makes it the answer for
     * a rule a comparison cannot state, and the cost is that it is only
     * re-evaluated when a `live()` field asks the schema to be rebuilt.
     *
     *     TextInput::make('contract_end_date')
     *         ->visible(fn (Get $get) => $get('employment_type') === 'contract')
     *
     * A closure asking for none of `FormState`, `Get`, or `Set` is called
     * with the record exactly as it always was — see `CallbackParameters`.
     *
     * @param  (Closure(?Model): bool)|bool  $condition
     */
    public function hidden(Closure|bool $condition = true): static
    {
        $this->hiddenUsing = $condition instanceof Closure
            ? $condition
            : static fn (): bool => $condition;

        return $this;
    }

    /**
     * The inverse of `hidden()`, and state-aware in the same way.
     *
     * @param  (Closure(?Model): bool)|bool  $condition
     */
    public function visible(Closure|bool $condition = true): static
    {
        $this->visibleUsing = $condition instanceof Closure
            ? $condition
            : static fn (): bool => $condition;

        return $this;
    }

    /**
     * Shows the field only while another field's value satisfies a
     * comparison, re-evaluated in the browser as the user types.
     *
     * Several conditions are ANDed. The comparison is *described* rather than
     * scripted — nothing executable crosses the wire — so it has to be
     * expressible as a `ConditionOperator`. Anything else belongs in a
     * server-side `visible()`, which is honest about not reacting.
     */
    public function visibleWhen(
        string $field,
        ConditionOperator $operator = ConditionOperator::Truthy,
        mixed $value = null,
    ): static {
        $this->visibleWhen[] = Condition::make($field, $operator, $value);

        return $this;
    }

    public function hiddenWhen(
        string $field,
        ConditionOperator $operator = ConditionOperator::Truthy,
        mixed $value = null,
    ): static {
        $this->hiddenWhen[] = Condition::make($field, $operator, $value);

        return $this;
    }

    /**
     * Asks the server to rebuild the form when this field changes.
     *
     * For a dependency the declarative conditions cannot express — a select
     * whose options come from another field's value, a computed total. Off by
     * default because a round trip per keystroke is the wrong default.
     */
    public function live(bool $onBlur = false, ?int $debounce = null): static
    {
        $this->live = true;
        $this->liveOnBlur = $onBlur;
        $this->liveDebounce = $debounce ?? $this->liveDebounce;

        return $this;
    }

    /**
     * Puts the label beside the control rather than above it.
     */
    public function inlineLabel(bool $inline = true): static
    {
        $this->inlineLabel = $inline;

        return $this;
    }

    /**
     * Called with the value the form was populated with.
     *
     * An observer, not a transformer: what it returns is ignored, so a hook
     * written for its side effect cannot blank the field by returning
     * nothing. `formatUsing()` is the hook that shapes a value on the way in.
     *
     * @param  Closure(mixed, ?Model): mixed  $callback
     */
    public function afterStateHydrated(Closure $callback): static
    {
        $this->afterStateHydrated = $callback;

        return $this;
    }

    /**
     * Runs when a live field's value changes, before the form is rebuilt.
     *
     * Receives the new value, the old one, and the record. For side effects
     * and for deciding what other fields should become — it returns nothing,
     * because a hook that both mutated and returned would leave two places to
     * change a value.
     *
     * ## Changing a sibling field
     *
     * Ask for a `Set` and the hook can say that another field is no longer
     * valid, which is the thing it could not previously do:
     *
     *     Select::make('department_id')
     *         ->live()
     *         ->afterStateUpdated(fn (Set $set) => $set('position_id', null));
     *
     * Rebuilding the options for `position_id` was never enough on its own.
     * The renderer preserves what the user typed across a rebuild — it has to,
     * or a rebuild would discard the rest of the form — so the stale value
     * came straight back and stayed on screen until the submit refused it.
     * A `Set` is recorded as a patch and overwrites the client's value; see
     * `FormState`.
     *
     * A hook that asks for none of `FormState`, `Get`, or `Set` is called
     * exactly as it always was — see `CallbackParameters`.
     *
     * @param  Closure(mixed, mixed, ?Model): void  $callback
     */
    public function afterStateUpdated(Closure $callback): static
    {
        $this->afterStateUpdated = $callback;

        return $this;
    }

    /**
     * Replaces the value on its way to the record.
     *
     * `mutateUsing()` is the same idea; this is the name Filament uses and
     * they share one implementation, so a schema written either way behaves
     * identically.
     *
     * @param  Closure(mixed, ?Model): mixed  $callback
     */
    public function dehydrateStateUsing(Closure $callback): static
    {
        $this->dehydrateStateUsing = $callback;

        return $this;
    }

    /**
     * Whether this field's value is persisted at all.
     *
     * A field can be rendered and validated and still not written — a
     * confirmation box, a field whose real effect is a side effect in a hook.
     *
     * @param  (Closure(?Model): bool)|bool  $condition
     */
    public function dehydrated(Closure|bool $condition = true): static
    {
        $this->dehydrated = $condition;

        return $this;
    }

    /**
     * Persists this field under a different model attribute.
     *
     * Form field names and column names are not always the same: a
     * `verified` toggle may write to `email_verified_at`. Making the mapping
     * explicit keeps the form readable without inventing a column.
     */
    public function dehydrateTo(string $attribute): static
    {
        $this->dehydrateTo = $attribute;

        return $this;
    }

    /**
     * Decides whether the submitted value is persisted at all.
     *
     * @param  Closure(mixed): bool  $callback
     */
    public function dehydrateWhen(Closure $callback): static
    {
        $this->dehydrateWhen = $callback;

        return $this;
    }

    /**
     * Transforms the validated value on its way into the model.
     *
     * @param  Closure(mixed, ?Model): mixed  $callback
     */
    public function mutateUsing(Closure $callback): static
    {
        $this->mutateUsing = $callback;

        return $this;
    }

    /**
     * Transforms the model value on its way into the form.
     *
     * @param  Closure(mixed, ?Model): mixed  $callback
     */
    public function formatUsing(Closure $callback): static
    {
        $this->formatUsing = $callback;

        return $this;
    }

    /**
     * Namespaces this field under a relation. Called by `Relationship` when
     * the field is added to it, never by a schema author.
     */
    public function prefixNameWith(string $prefix): static
    {
        $this->namePrefix = $prefix;

        return $this;
    }

    /**
     * The name on the wire and in the rules, which is the attribute prefixed
     * by its relation when it has one.
     */
    public function getName(): string
    {
        return $this->namePrefix === '' ? $this->name : $this->namePrefix.'.'.$this->name;
    }

    /**
     * The attribute this field reads and writes on its own record, without
     * the relation namespace.
     */
    public function getAttribute(): string
    {
        return $this->name;
    }

    public function getLabel(): string
    {
        return $this->label ?? Label::resolve(
            'fields',
            $this->name,
            fn (): string => Str::headline($this->name),
        );
    }

    public function getDehydrateKey(): string
    {
        return $this->dehydrateTo ?? $this->name;
    }

    /**
     * Whether this field is absent from the given page entirely.
     *
     * "Absent" is one answer with several sources, and they are checked in
     * the order that makes the strictest one win: an explicit `hidden()`,
     * then a `visible()` that says no, then the page lists. A field that is
     * absent is not rendered, not validated, and not persisted — the three
     * follow from one decision rather than being asked separately.
     */
    public function isHiddenOn(string $page, ?Model $record = null): bool
    {
        if ($this->hiddenUsing !== null && $this->condition($this->hiddenUsing, $record)) {
            return true;
        }

        if ($this->visibleUsing !== null && ! $this->condition($this->visibleUsing, $record)) {
            return true;
        }

        if ($this->visibleOn !== null && ! in_array($page, $this->visibleOn, true)) {
            return true;
        }

        return in_array($page, $this->hiddenOn, true);
    }

    /**
     * Runs a visibility closure with whatever it asked for.
     *
     * The state is the one the schema is currently being built against, which
     * is empty on a first render and holds what has been typed on a rebuild.
     * That is what lets a `live()` field change which fields exist rather than
     * only what they contain.
     */
    private function condition(Closure $condition, ?Model $record): bool
    {
        return (bool) CallbackParameters::call(
            $condition,
            [$record],
            $this->stateContext ?? new FormState,
            ['record' => $record],
        );
    }

    /**
     * The values the schema is being built against. Set by `FormSchema`, for
     * the reason `onPage()` is: `isHiddenOn()` is called from several places
     * and none of them should have to carry a parameter the schema already
     * knows.
     */
    public function withFormState(?FormState $state): static
    {
        $this->stateContext = $state;

        return $this;
    }

    public function isDisabledOn(string $page, ?Model $record = null): bool
    {
        // Immutability is a stronger statement than disabling and includes it:
        // a field the server will not write has no business being editable.
        if ($this->isImmutableOn($page)) {
            return true;
        }

        if ($this->disabled) {
            return true;
        }

        if ($this->disabledUsing !== null && ($this->disabledUsing)($record)) {
            return true;
        }

        return in_array($page, $this->disabledOn, true);
    }

    /**
     * Whether the browser should be showing this field right now.
     *
     * The declarative conditions only. The server-side ones are already
     * settled by the time a schema is serialized — a field they hid is not in
     * the payload at all.
     *
     * @param  array<string, mixed>  $state
     */
    public function matchesConditions(array $state): bool
    {
        foreach ($this->visibleWhen as $condition) {
            if (! $condition->matches($state)) {
                return false;
            }
        }

        foreach ($this->hiddenWhen as $condition) {
            if ($condition->matches($state)) {
                return false;
            }
        }

        return true;
    }

    public function isLive(): bool
    {
        return $this->live;
    }

    /**
     * Whether the submitted value should reach the record at all.
     */
    public function isDehydrated(?Model $record = null): bool
    {
        // The half that makes `immutable()` a guard rather than a style. It
        // wins over an explicit `dehydrated(true)`: declaring a field
        // immutable and then asking for it to be written is a contradiction,
        // and the safe reading of a contradiction is the one that writes
        // nothing.
        if ($this->isImmutableOn()) {
            return false;
        }

        if ($this->dehydrated === null) {
            return true;
        }

        return $this->dehydrated instanceof Closure
            ? ($this->dehydrated)($record)
            : $this->dehydrated;
    }

    /**
     * Runs the update hook. The caller has already decided the value changed.
     *
     * `$formState` is optional so the signature stays compatible with any
     * caller that predates it; without one the hook simply has no siblings to
     * read and no patch to record.
     */
    public function handleStateUpdated(
        mixed $state,
        mixed $previous,
        ?Model $record = null,
        ?FormState $formState = null,
    ): void {
        if ($this->afterStateUpdated === null) {
            return;
        }

        CallbackParameters::call(
            $this->afterStateUpdated,
            [$state, $previous, $record],
            $formState ?? new FormState,
            ['state' => $state, 'value' => $state, 'previous' => $previous, 'old' => $previous, 'record' => $record],
        );
    }

    /**
     * @return list<Field>
     */
    public function fields(): array
    {
        return [$this];
    }

    /**
     * Rules for each element of a field whose value is a list.
     *
     * Returned separately because Laravel expects them under `field.*` rather
     * than inside the field's own rule list. Empty for a scalar field.
     *
     * @return list<mixed>
     */
    public function elementRules(): array
    {
        return [];
    }

    /**
     * Rules for the fields nested inside this one, already keyed by their
     * full path.
     *
     * A repeater's children live at `items.*.title`, which is a key the
     * schema cannot derive from the outside — only the field that owns them
     * knows how deep they are.
     *
     * @return array<string, list<mixed>>
     */
    public function nestedRules(?Model $record = null): array
    {
        return [];
    }

    /**
     * The full Laravel rule set for this field.
     *
     * @return list<mixed>
     */
    public function validationRules(?Model $record): array
    {
        $rules = [$this->isRequired() ? 'required' : 'nullable'];

        $rules = [...$rules, ...$this->typeRules(), ...$this->rules];

        if ($this->rulesUsing !== null) {
            $rules = [...$rules, ...($this->rulesUsing)($record)];
        }

        return $rules;
    }

    /**
     * Whether the submitted value should reach the model.
     */
    public function shouldDehydrate(mixed $value): bool
    {
        return $this->dehydrateWhen === null || ($this->dehydrateWhen)($value);
    }

    /**
     * The value on its way to the record.
     *
     * `dehydrateStateUsing()` and `mutateUsing()` are the same idea under two
     * names — Filament's and this framework's — so they share one
     * implementation and a schema written either way behaves identically.
     */
    public function mutate(mixed $value, ?Model $record): mixed
    {
        $callback = $this->dehydrateStateUsing ?? $this->mutateUsing;

        return $callback === null ? $value : $callback($value, $record);
    }

    /**
     * The value the form is populated with.
     */
    public function formValue(?Model $record): mixed
    {
        $value = $record === null
            ? $this->default
            : data_get($record, $this->name);

        $value = $this->formatUsing !== null
            ? ($this->formatUsing)($value, $record)
            : $this->castForForm($value);

        // Observed, not applied. `formatUsing()` is the hook that changes a
        // value on the way in; this one runs beside it so a field can react
        // to what it was given — and a hook written for its side effect
        // cannot blank the field by returning nothing.
        if ($this->afterStateHydrated !== null) {
            ($this->afterStateHydrated)($value, $record);
        }

        return $value;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function toArray(?Model $record, string $page): ?array
    {
        if ($this->isHiddenOn($page, $record)) {
            return null;
        }

        return [
            'component' => 'field',
            'name' => $this->getName(),
            'label' => $this->getLabel(),
            'type' => $this->type()->value,
            'value' => $this->formValue($record),
            'placeholder' => $this->placeholder,
            'helperText' => $this->helperText,
            'required' => $this->isRequired($page),
            'disabled' => $this->isDisabledOn($page, $record),
            'inlineLabel' => $this->inlineLabel,
            'columnSpan' => $this->columnSpan,
            // Re-evaluated in the browser as the user types. Descriptions of
            // comparisons, never code.
            'conditions' => [
                'visibleWhen' => array_map(
                    static fn (Condition $condition): array => $condition->toArray(),
                    $this->visibleWhen,
                ),
                'hiddenWhen' => array_map(
                    static fn (Condition $condition): array => $condition->toArray(),
                    $this->hiddenWhen,
                ),
            ],
            'live' => $this->live ? [
                'onBlur' => $this->liveOnBlur,
                'debounce' => $this->liveDebounce,
            ] : null,
            // What the browser may check before submitting. The server still
            // validates everything; this only saves a round trip.
            'validation' => $this->validationHints($page),
            ...$this->extraArray(),
        ];
    }

    /**
     * The subset of the rules a browser can honestly check.
     *
     * Derived from the same declarations the server validates with, so the
     * two cannot drift into disagreeing. Anything that needs the database —
     * `unique`, `exists` — is deliberately absent: a frontend that guessed at
     * those would be confidently wrong.
     *
     * @return array<string, mixed>
     */
    protected function validationHints(?string $page = null): array
    {
        $hints = ['required' => $this->isRequired($page)];

        foreach ([...$this->typeRules(), ...$this->rules] as $rule) {
            if (! is_string($rule)) {
                continue;
            }

            [$name, $argument] = array_pad(explode(':', $rule, 2), 2, null);

            match ($name) {
                'email' => $hints['email'] = true,
                'numeric', 'integer' => $hints['numeric'] = true,
                'url' => $hints['url'] = true,
                'min' => $hints['min'] = is_numeric($argument) ? (float) $argument : null,
                'max' => $hints['max'] = is_numeric($argument) ? (float) $argument : null,
                'confirmed' => $hints['confirmed'] = true,
                default => null,
            };
        }

        return array_filter($hints, static fn (mixed $value): bool => $value !== null);
    }

    /**
     * Rules implied by the field type, such as `email` or `boolean`.
     *
     * @return list<mixed>
     */
    protected function typeRules(): array
    {
        return [];
    }

    /**
     * Normalizes a model value into something the control can bind to.
     */
    protected function castForForm(mixed $value): mixed
    {
        return $value;
    }

    /**
     * @return array<string, mixed>
     */
    protected function extraArray(): array
    {
        return [];
    }
}
