<?php

declare(strict_types=1);

namespace PandaPanel\Actions;

use Closure;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use PandaPanel\Actions\Enums\ActionType;
use PandaPanel\Actions\Enums\ActionVariant;
use PandaPanel\Actions\Enums\ModalWidth;
use PandaPanel\Actions\Support\Modal;
use PandaPanel\Exceptions\PanelSchemaException;
use PandaPanel\Forms\FormSchema;
use PandaPanel\Support\DatabaseTransaction;
use PandaPanel\Support\Label;
use PandaPanel\Support\SafeUrl;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * A backend-owned operation the frontend can request by name.
 *
 * The serialized definition carries a label, an icon key, a variant, and
 * confirmation copy. It never carries the handler. The frontend sends an
 * action name, a record key, and an optional payload; the backend looks the
 * action up in the schema that declared it, authorizes it, and runs it.
 *
 * That indirection is the point: an action that is not declared on the
 * resource being addressed does not exist, no matter what the request says.
 */
class Action
{
    protected ?string $label = null;

    protected ?string $icon = null;

    protected ActionVariant $variant = ActionVariant::Ghost;

    protected bool $requiresConfirmation = false;

    protected ?string $confirmationHeading = null;

    protected ?string $confirmationDescription = null;

    protected ?string $confirmationButton = null;

    protected ?string $successMessage = null;

    /** @var (Closure(?Model): bool)|null */
    protected ?Closure $authorizeUsing = null;

    /** @var (Closure(?Model): bool)|null */
    protected ?Closure $visibleUsing = null;

    /** @var (Closure(Model): string)|null */
    protected ?Closure $urlUsing = null;

    /** @var (Closure(?Model): string)|null */
    protected ?Closure $formUrlUsing = null;

    /** @var (Closure(Model, array<string, mixed>): void)|null */
    protected ?Closure $handleUsing = null;

    /** @var (Closure(Model, array<string, mixed>): void)|null */
    protected ?Closure $beforeUsing = null;

    /** @var (Closure(Model, array<string, mixed>): void)|null */
    protected ?Closure $afterUsing = null;

    /** @var (Closure(Collection<int, Model>, array<string, mixed>): void)|null */
    protected ?Closure $handleBulkUsing = null;

    /** @var (Closure(array<string, mixed>): void)|null */
    protected ?Closure $handleTableUsing = null;

    /** @var (Closure(Model): bool)|null */
    protected ?Closure $authorizeEachUsing = null;

    /** @var (Closure(int): string)|null */
    protected ?Closure $successMessageUsing = null;

    /** How many records the last bulk run touched, for the message. */
    protected int $affected = 0;

    /**
     * How the dialog behaves. Built lazily: most actions never open one, and
     * an action that does not is not carrying a modal's worth of defaults.
     */
    protected ?Modal $modal = null;

    /** @var (Closure(?Model): FormSchema)|null */
    protected ?Closure $schemaUsing = null;

    /**
     * Actions reachable from inside this one's dialog.
     *
     * They are not rendered beside the trigger and are not found by the
     * table's own lookup — the parent action is the only route to them, which
     * is what keeps "runnable from this modal" from meaning "runnable by
     * name from anywhere".
     *
     * @var array<string, static>
     */
    protected array $modalActions = [];

    /**
     * `null` inherits the panel's setting.
     */
    protected ?bool $databaseTransaction = null;

    final public function __construct(protected readonly string $name)
    {
        if (trim($name) === '') {
            throw PanelSchemaException::emptyName('action');
        }

        // The name travels to the action endpoint as an identifier and is
        // matched there against the schema. A space or a slash in it is a name
        // the endpoint can be asked for but never matches, which renders as a
        // button that fails only when pressed.
        if (preg_match('/^[A-Za-z0-9_.-]+$/', $name) !== 1) {
            throw PanelSchemaException::unusableActionName($name);
        }
    }

    /**
     * Whether pressing this would do anything at all.
     *
     * Checked where a set of actions is declared rather than per row, so an
     * action that leads nowhere is refused once at definition time instead of
     * being drawn for every record and disappointing on click.
     */
    public function isInert(): bool
    {
        return $this->urlUsing === null
            && $this->handleUsing === null
            && $this->handleBulkUsing === null
            && $this->handleTableUsing === null
            && $this->schemaUsing === null
            && $this->formUrlUsing === null
            && $this->modal === null
            && $this->modalActions === [];
    }

    public static function make(string $name): static
    {
        $action = new static($name);

        // The panel's house style, applied as the action is built so anything
        // the schema then sets still wins. Read through the current panel
        // rather than a static configurator, so two panels can differ and
        // nothing leaks between requests.
        panel()?->actionConfigurator()?->__invoke($action);

        return $action;
    }

    public function label(string $label): static
    {
        $this->label = $label;

        return $this;
    }

    public function icon(string $icon): static
    {
        $this->icon = $icon;

        return $this;
    }

    public function variant(ActionVariant $variant): static
    {
        $this->variant = $variant;

        return $this;
    }

    public function requiresConfirmation(
        bool $requires = true,
        ?string $heading = null,
        ?string $description = null,
        ?string $button = null,
    ): static {
        $this->requiresConfirmation = $requires;
        $this->confirmationHeading = $heading;
        $this->confirmationDescription = $description;
        $this->confirmationButton = $button;

        return $this;
    }

    public function successMessage(string $message): static
    {
        $this->successMessage = $message;

        return $this;
    }

    /**
     * A message that knows how many records were touched.
     *
     * "3 orders approved" is a different statement from "Orders approved",
     * and after a bulk run the count is the part somebody actually wants.
     *
     * @param  Closure(int): string  $callback
     */
    public function successMessageUsing(Closure $callback): static
    {
        $this->successMessageUsing = $callback;

        return $this;
    }

    /**
     * Authorizes every record of a bulk run, whatever the handler is.
     *
     * `authorize()` answers for the action; this answers for each record it
     * is about to touch. Without it a bulk action inherits only the
     * collective check, and "may run this" is not "may run this on these".
     *
     * @param  Closure(Model): bool  $callback
     */
    public function authorizeEachUsing(Closure $callback): static
    {
        $this->authorizeEachUsing = $callback;

        return $this;
    }

    public function isAuthorizedForEach(Model $record): bool
    {
        if ($this->authorizeEachUsing !== null) {
            return ($this->authorizeEachUsing)($record);
        }

        return $this->isAuthorizedFor($record);
    }

    /**
     * @param  Closure(?Model): bool  $callback
     */
    public function authorize(Closure $callback): static
    {
        $this->authorizeUsing = $callback;

        return $this;
    }

    /**
     * Hides the action without implying it is forbidden. Authorization is a
     * separate question and is always asked on execution.
     *
     * @param  Closure(?Model): bool  $callback
     */
    public function visible(Closure $callback): static
    {
        $this->visibleUsing = $callback;

        return $this;
    }

    /**
     * Turns the action into a link. Link actions have no handler.
     *
     * @param  Closure(Model): string  $callback
     */
    public function url(Closure $callback): static
    {
        $this->urlUsing = $callback;

        return $this;
    }

    /**
     * Turns the action into a dialog whose form is fetched from `$callback`'s
     * URL when it opens.
     *
     * The schema is not serialized into the row: a table shows one button per
     * record, and shipping a filled-in form beside every one of them would
     * put twenty copies of the same schema on the wire to open at most one.
     *
     * @param  Closure(?Model): string  $callback
     */
    public function form(Closure $callback): static
    {
        $this->formUrlUsing = $callback;

        return $this;
    }

    /**
     * The handler.
     *
     * Given the record and, when the action declared a form of its own, the
     * validated data that form submitted. A handler that takes one argument
     * never sees the second, which is why declaring a form is additive.
     *
     * @param  Closure(Model, array<string, mixed>): void  $callback
     */
    public function action(Closure $callback): static
    {
        $this->handleUsing = $callback;

        return $this;
    }

    /**
     * Runs before the handler, inside the same transaction. Throwing here
     * aborts the action and rolls back.
     *
     * These live on the action rather than on a page because the action
     * endpoint executes without a page instance: a hook declared on the page
     * would never be called.
     *
     * @param  Closure(Model, array<string, mixed>): void  $callback
     */
    public function before(Closure $callback): static
    {
        $this->beforeUsing = $callback;

        return $this;
    }

    /**
     * Runs after the handler, inside the same transaction.
     *
     * @param  Closure(Model, array<string, mixed>): void  $callback
     */
    public function after(Closure $callback): static
    {
        $this->afterUsing = $callback;

        return $this;
    }

    /**
     * @param  Closure(Collection<int, Model>, array<string, mixed>): void  $callback
     */
    public function bulkAction(Closure $callback): static
    {
        $this->handleBulkUsing = $callback;

        return $this;
    }

    /**
     * A handler that takes no record, for an action in a header, a toolbar,
     * or an empty state — one that acts on the table rather than on a row.
     *
     * @param  Closure(array<string, mixed>): void  $callback
     */
    public function tableAction(Closure $callback): static
    {
        $this->handleTableUsing = $callback;

        return $this;
    }

    public function isTableExecutable(): bool
    {
        return $this->handleTableUsing !== null;
    }

    /**
     * The hooks and the handler share one transaction, exactly as they do for
     * a record action.
     *
     * @param  array<string, mixed>  $data  what the action's own form submitted
     */
    public function executeWithoutRecord(array $data = []): void
    {
        if ($this->handleTableUsing === null) {
            return;
        }

        DatabaseTransaction::run($this->databaseTransaction, function () use ($data): void {
            ($this->handleTableUsing)($data);
        });
    }

    /**
     * Configures the dialog this action opens.
     *
     * A callback rather than thirty setters on the action itself: a modal's
     * settings are one subject, and keeping them one object is what lets a
     * panel describe a house style for dialogs without touching actions.
     *
     * @param  Closure(Modal): void  $callback
     */
    public function modal(Closure $callback): static
    {
        $callback($this->getModal());

        return $this;
    }

    /**
     * The most-reached-for settings, so the common case is one call.
     */
    public function modalWidth(ModalWidth $width): static
    {
        $this->getModal()->width($width);

        return $this;
    }

    public function slideOver(bool $slideOver = true): static
    {
        $this->getModal()->slideOver($slideOver);

        return $this;
    }

    public function modalHeading(string $heading): static
    {
        $this->getModal()->heading($heading);

        return $this;
    }

    public function modalDescription(string $description): static
    {
        $this->getModal()->description($description);

        return $this;
    }

    public function modalSubmitLabel(string $label): static
    {
        $this->getModal()->submitLabel($label);

        return $this;
    }

    /**
     * Draws the dialog's own content with a component of this application's.
     *
     * @param  array<string, mixed>  $config
     */
    public function modalContent(string $component, array $config = []): static
    {
        $this->getModal()->content($component, $config);

        return $this;
    }

    public function getModal(): Modal
    {
        return $this->modal ??= Modal::make();
    }

    public function hasModal(): bool
    {
        return $this->modal !== null;
    }

    /**
     * Gives the action a form of its own, opened in its dialog.
     *
     * The schema is built per record and fetched when the dialog opens rather
     * than serialized into every row — a table of twenty records would
     * otherwise ship twenty copies of a form to open at most one. A schema
     * holding a `Wizard` is a stepped dialog and needs nothing else said.
     *
     * @param  Closure(?Model): FormSchema  $callback
     */
    public function schema(Closure $callback): static
    {
        $this->schemaUsing = $callback;

        return $this;
    }

    public function hasForm(): bool
    {
        return $this->schemaUsing !== null;
    }

    /**
     * The form for one record, or for none on a table action.
     */
    public function resolveSchema(?Model $record): ?FormSchema
    {
        return $this->schemaUsing === null ? null : ($this->schemaUsing)($record);
    }

    /**
     * Registers actions that can be run from inside this action's dialog.
     *
     * @param  array<array-key, static>  $actions
     */
    public function registerModalActions(array $actions): static
    {
        foreach ($actions as $action) {
            $this->modalActions[$action->getName()] = $action;
        }

        return $this;
    }

    /**
     * @return array<string, static>
     */
    public function getModalActions(): array
    {
        return $this->modalActions;
    }

    public function getModalAction(string $name): ?static
    {
        return $this->modalActions[$name] ?? null;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getLabel(): string
    {
        return $this->label ?? Label::resolve(
            'actions',
            $this->name,
            fn (): string => Str::headline($this->name),
        );
    }

    /**
     * The icon registry key this action asks for, whatever a record would
     * make of its visibility.
     *
     * `toArray()` answers null for a record the action is hidden or refused
     * for, which makes it useless for asking what an action *declares*.
     */
    public function getIcon(): ?string
    {
        return $this->icon;
    }

    /**
     * The variant this action asks for, so a panel-wide configurator can
     * decide something from it — "everything destructive confirms" needs to
     * be able to ask which ones are destructive.
     */
    public function getVariant(): ActionVariant
    {
        return $this->variant;
    }

    public function getSuccessMessage(): string
    {
        if ($this->successMessageUsing !== null) {
            return ($this->successMessageUsing)($this->affected);
        }

        return $this->successMessage ?? sprintf('%s completed.', $this->getLabel());
    }

    /**
     * How many records the last run touched. One for a record action, the
     * size of the selection for a bulk one.
     */
    public function affectedCount(): int
    {
        return $this->affected;
    }

    public function type(): ActionType
    {
        return match (true) {
            $this->urlUsing !== null => ActionType::Link,
            $this->formUrlUsing !== null || $this->schemaUsing !== null => ActionType::Form,
            default => ActionType::Callback,
        };
    }

    public function isVisibleFor(?Model $record): bool
    {
        return $this->visibleUsing === null || ($this->visibleUsing)($record);
    }

    public function isAuthorizedFor(?Model $record): bool
    {
        return $this->authorizeUsing === null || ($this->authorizeUsing)($record);
    }

    public function isExecutable(): bool
    {
        return $this->handleUsing !== null;
    }

    public function isBulkExecutable(): bool
    {
        return $this->handleBulkUsing !== null;
    }

    /**
     * Overrides the panel for this action alone.
     *
     * `->databaseTransaction(false)` on an action that calls an external
     * service keeps the connection from being held open across a network
     * round trip; `->databaseTransaction()` turns it on inside a panel that
     * has them off.
     */
    public function databaseTransaction(bool $databaseTransaction = true): static
    {
        $this->databaseTransaction = $databaseTransaction;

        return $this;
    }

    public function hasDatabaseTransaction(): ?bool
    {
        return $this->databaseTransaction;
    }

    /**
     * The hooks and the handler share one transaction, so an `after` hook
     * that throws undoes the operation rather than leaving it half applied.
     *
     * `$data` is what the action's own form submitted, already validated and
     * dehydrated by the schema that declared it. A handler that takes one
     * argument simply never sees it, which is why every existing action is
     * unaffected.
     *
     * @param  array<string, mixed>  $data
     */
    public function execute(Model $record, array $data = []): void
    {
        if ($this->handleUsing === null) {
            return;
        }

        $this->affected = max($this->affected, 1);

        DatabaseTransaction::run($this->databaseTransaction, function () use ($record, $data): void {
            if ($this->beforeUsing !== null) {
                ($this->beforeUsing)($record, $data);
            }

            ($this->handleUsing)($record, $data);

            if ($this->afterUsing !== null) {
                ($this->afterUsing)($record, $data);
            }
        });
    }

    /**
     * @param  Collection<int, Model>  $records
     * @param  array<string, mixed>  $data  what the action's own form submitted
     */
    public function executeBulk(Collection $records, array $data = []): void
    {
        // Every record is authorized before any is touched, whatever the
        // handler does: "all or nothing" has to be decided before the first
        // write, not discovered halfway through.
        foreach ($records as $record) {
            if (! $this->isAuthorizedForEach($record)) {
                throw new HttpException(403, __('panda-panel::actions.bulk_denied', [
                    'action' => Str::lower($this->getLabel()),
                ]));
            }
        }

        $this->affected = $records->count();

        // One transaction for the selection, not one per record and not none
        // at all. Both of those were the behaviour, and both surprised: a
        // `bulkAction()` closure ran unwrapped, and the per-record fallback
        // gave ten records ten transactions, so a failure on the seventh left
        // six committed and six rows changed by an operation the user was told
        // had failed.
        //
        // "All or nothing" is what a bulk action reads as, and it is already
        // what every built-in one guarantees by opening its own transaction.
        // Doing it here makes those a savepoint — the same outcome — and means
        // a hand-written bulk action gets the guarantee without knowing to ask.
        //
        // Authorization stays outside it: it is a read that ends in a 403, and
        // there is nothing to roll back when nothing has been written.
        DatabaseTransaction::run($this->databaseTransaction, function () use ($records, $data): void {
            if ($this->handleBulkUsing !== null) {
                ($this->handleBulkUsing)($records, $data);

                return;
            }

            foreach ($records as $record) {
                $this->execute($record, $data);
            }
        });
    }

    /**
     * Null when the action is hidden or unauthorized for this record, so an
     * action the user may not run is never rendered in the first place.
     *
     * @return array<string, mixed>|null
     */
    public function toArray(?Model $record = null): ?array
    {
        if (! $this->isVisibleFor($record) || ! $this->isAuthorizedFor($record)) {
            return null;
        }

        $type = $this->type();
        $url = $record !== null && $this->urlUsing !== null
            ? SafeUrl::sanitize(($this->urlUsing)($record))
            : null;

        if ($type === ActionType::Link && $url === null) {
            return null;
        }

        return [
            'name' => $this->name,
            'label' => $this->getLabel(),
            'icon' => $this->icon,
            'variant' => $this->variant->value,
            'type' => $type->value,
            'url' => $url,
            'formUrl' => $this->formUrlUsing === null ? null : ($this->formUrlUsing)($record),
            // A form the action declares itself is fetched from the panel's
            // action-form endpoint, which the client already holds. Only a
            // relation form carries a URL of its own, because that one names
            // an owner and an operation this action knows nothing about.
            'hasForm' => $this->schemaUsing !== null,
            'modal' => $this->modal?->toArray(),
            'modalActions' => array_values(array_filter(array_map(
                static fn (Action $action): ?array => $action->toArray($record),
                $this->modalActions,
            ))),
            'confirmation' => $this->requiresConfirmation ? [
                'heading' => $this->confirmationHeading ?? sprintf('%s?', $this->getLabel()),
                'description' => $this->confirmationDescription
                    ?? __('panda-panel::actions.confirmation.description'),
                'button' => $this->confirmationButton ?? $this->getLabel(),
            ] : null,
        ];
    }
}
