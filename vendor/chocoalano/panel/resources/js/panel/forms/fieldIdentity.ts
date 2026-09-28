import { computed, inject, onUnmounted, provide } from 'vue';
import type { ComputedRef, InjectionKey } from 'vue';

/**
 * Where a field is, in the two senses a form needs.
 *
 * A field has two identities and they are not the same identity. One is the
 * **state path**: what the value is called when it is submitted and what an
 * error is keyed by when it comes back — `title`, or `items.0.title` inside a
 * repeater. The other is the **DOM id**: what a `<label for>` points at and
 * what `aria-describedby` names.
 *
 * They used to be the same string, and that is the whole of U02. Every field
 * rendered `id="{field.name}"`, and a repeater renders the same sub-schema for
 * every entry — so two entries produced two controls called `name`, and
 * clicking the second entry's label focused the first entry's input. The
 * duplicate was invisible until somebody used a repeater with a label.
 *
 * Separating them fixes that without touching what is submitted. The scope
 * below carries a DOM prefix and a state-path prefix, and a repeater sets both
 * for its entries: the DOM prefix makes ids unique, the path prefix is what the
 * server already keys errors by. Nothing about the payload changes.
 */
export type FieldScope = {
    /** Prepended to every DOM id below this point. Ends in `-`, or is empty. */
    dom: string;
    /** Prepended to the state path. Ends in `.`, or is empty. */
    path: string;
};

const ROOT: FieldScope = { dom: '', path: '' };

const FIELD_SCOPE: InjectionKey<() => FieldScope> = Symbol(
    'panel.form.fieldScope',
);

/**
 * Nests a scope inside whatever scope is already in effect, so a repeater
 * inside a repeater composes rather than replaces.
 */
export function provideFieldScope(scope: () => FieldScope): void {
    const parent = useFieldScope();

    provide(FIELD_SCOPE, () => {
        const outer = parent();
        const inner = scope();

        return {
            dom: `${outer.dom}${inner.dom}`,
            path: `${outer.path}${inner.path}`,
        };
    });
}

/** The root scope outside a repeater, which is the overwhelming majority. */
export function useFieldScope(): () => FieldScope {
    return inject(FIELD_SCOPE, () => ROOT);
}

/**
 * A DOM id is not a state path: it has to survive being put in an attribute
 * and matched by a selector, and a state path contains dots and can contain
 * anything a column is called.
 */
function toToken(value: string): string {
    return value.replace(/[^A-Za-z0-9_-]/g, '-');
}

export type FieldIdentity = {
    /** What the control carries and what the label points at. */
    controlId: string;
    /** The helper paragraph, when there is one. */
    helperId: string;
    /** The error message, when there is one. */
    errorId: string;
    /**
     * The two above, in reading order, or undefined when neither exists.
     *
     * Both when both exist: an error explains why this value was refused, and
     * the helper still explains what the field wants. Dropping the helper the
     * moment a field goes red takes the instructions away exactly when they
     * are most needed.
     */
    describedBy: string | undefined;
    /** The full state path, which is what an error is keyed by. */
    path: string;
};

export function useFieldIdentity(
    name: () => string,
    has: { helper: () => boolean; error: () => boolean },
): ComputedRef<FieldIdentity> {
    const scope = useFieldScope();

    return computed<FieldIdentity>(() => {
        const { dom, path } = scope();
        const controlId = `${dom}${toToken(name())}`;
        const helperId = `${controlId}-helper`;
        const errorId = `${controlId}-error`;
        const described = [
            has.helper() ? helperId : null,
            has.error() ? errorId : null,
        ].filter((value): value is string => value !== null);

        return {
            controlId,
            helperId,
            errorId,
            describedBy: described.length > 0 ? described.join(' ') : undefined,
            path: `${path}${name()}`,
        };
    });
}

/**
 * Where the form finds the control that a given error belongs to.
 *
 * A failed submit has to move focus to the first field the server refused, and
 * "first" means first on screen — not first key in an object, whose order is
 * whatever the server serialised. Fields register themselves as they mount,
 * which is render order, and the form walks that list.
 *
 * Registration is by state path, so an error keyed `items.0.title` finds the
 * control inside the repeater entry that produced it, whose DOM id is nothing
 * like its path.
 */
export type RegisteredField = {
    controlId: string;
    /** What the field is called on screen, for a summary that links to it. */
    label: string;
};

export type FieldRegistry = {
    register: (path: string, field: RegisteredField) => void;
    unregister: (path: string) => void;
    /** Registered paths in mount order. */
    paths: () => string[];
    get: (path: string) => RegisteredField | null;
    /**
     * The state path a DOM id belongs to.
     *
     * The form listens for `focusout` once rather than having twenty-five
     * renderers each emit one, and it has to turn the blurred element back
     * into the field that blurred. That used to be free because the id *was*
     * the name; it is not any more — `profile.bio` is a legal state path and
     * a poor id — so the mapping is kept rather than assumed.
     */
    pathFor: (controlId: string) => string | null;
};

const FIELD_REGISTRY: InjectionKey<FieldRegistry> = Symbol(
    'panel.form.fieldRegistry',
);

export function createFieldRegistry(): FieldRegistry {
    // A Map preserves insertion order, which is the only reason this is not a
    // plain object: the order *is* the answer to "which error is first".
    const entries = new Map<string, RegisteredField>();

    return {
        register: (path, field) => void entries.set(path, field),
        unregister: (path) => void entries.delete(path),
        paths: () => [...entries.keys()],
        get: (path) => entries.get(path) ?? null,
        pathFor: (controlId) => {
            for (const [path, field] of entries) {
                if (field.controlId === controlId) {
                    return path;
                }
            }

            return null;
        },
    };
}

export function provideFieldRegistry(registry: FieldRegistry): void {
    provide(FIELD_REGISTRY, registry);
}

/**
 * Registers for as long as the field is mounted.
 *
 * Null outside a form — a field rendered on its own still works, it simply has
 * nothing to report to.
 */
export function useFieldRegistration(
    identity: ComputedRef<FieldIdentity>,
    label: () => string,
): void {
    const registry = inject(FIELD_REGISTRY, null);

    if (registry === null) {
        return;
    }

    // Registered eagerly rather than in `onMounted`, so the order is the order
    // components are set up in, which is document order. `onMounted` fires
    // children-first and would put a nested field ahead of the one above it.
    registry.register(identity.value.path, {
        controlId: identity.value.controlId,
        label: label(),
    });

    onUnmounted(() => registry.unregister(identity.value.path));
}
