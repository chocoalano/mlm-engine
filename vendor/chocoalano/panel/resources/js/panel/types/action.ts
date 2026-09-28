/**
 * Mirrors `PandaPanel\Actions\Action::toArray()`.
 *
 * A definition carries a name and presentation, never a handler. A `link`
 * action navigates to the server-produced URL; a `callback` action posts its
 * name to the action endpoint, where the backend decides what it means.
 */
export type ActionVariant =
    'default' | 'secondary' | 'outline' | 'ghost' | 'destructive';

export interface ActionConfirmation {
    heading: string;
    description: string;
    button: string;
}

/** Mirrors `PandaPanel\Actions\Enums\ModalWidth`. */
export type ModalWidth = 'sm' | 'md' | 'lg' | 'xl' | '2xl' | '4xl' | 'screen';

/**
 * How an action's dialog behaves. Mirrors `PandaPanel\Actions\Support\Modal`.
 *
 * Absent on an action that never opens one, which is most of them — an action
 * that does not is not carrying a modal's worth of defaults across the wire.
 */
export interface ModalDefinition {
    width: ModalWidth;
    /** Opens from the side rather than the centre. Same content either way. */
    slideOver: boolean;
    stickyHeader: boolean;
    stickyFooter: boolean;
    closeByClickingAway: boolean;
    closeByEscaping: boolean;
    autofocus: boolean;
    heading: string | null;
    description: string | null;
    submitLabel: string | null;
    cancelLabel: string | null;
    cancel: boolean;
    /** A build-time registry key, never markup. */
    componentName: string | null;
    config: Record<string, unknown>;
}

export interface ActionDefinition {
    name: string;
    label: string;
    icon: string | null;
    variant: ActionVariant;
    type: 'link' | 'callback' | 'form';
    url: string | null;
    /**
     * Where a relation's `form` action fetches its schema. An action that
     * declares a form of its own uses the panel's action-form endpoint
     * instead — see `hasForm`.
     */
    formUrl: string | null;
    /**
     * Whether the action carries a form the panel endpoint can describe. The
     * schema is never in the row itself: a table of twenty records would
     * otherwise ship twenty copies of a form to open at most one.
     */
    hasForm: boolean;
    modal: ModalDefinition | null;
    /**
     * Actions reachable from inside this one's dialog. They are not rendered
     * beside the trigger and are only found through their parent.
     */
    modalActions: ActionDefinition[];
    confirmation: ActionConfirmation | null;
}

export interface ActionEndpoints {
    record: string;
    bulk: string;
    reorder: string;
    /** Where an editable cell writes. */
    cell: string;
    /** Where an action with no record runs. */
    table: string;
    /** Where an action's own form is fetched from and submitted to. */
    form: string;
    /** Where a view page's own actions run. */
    infolist: string;
}
