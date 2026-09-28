import { nextTick } from 'vue';
import type { FieldRegistry } from '@/panel/forms/fieldIdentity';

/**
 * What a control has to be for focus to land on it.
 *
 * A field can be a component the host registered, and there is no rule that
 * such a component has a focusable element at all. Rather than focus nothing
 * and leave the user where they were, the search widens: the element itself,
 * then the first focusable thing inside it, then the element made focusable
 * for exactly this purpose.
 */
const FOCUSABLE =
    'input, select, textarea, button, [href], [tabindex]:not([tabindex="-1"])';

function focusable(element: HTMLElement): HTMLElement | null {
    if (element.matches(FOCUSABLE) && !element.hasAttribute('disabled')) {
        return element;
    }

    const inside = element.querySelector<HTMLElement>(FOCUSABLE);

    if (inside !== null) {
        return inside;
    }

    // Nothing inside can take focus, so the container is made able to. `-1`
    // rather than `0`: this is a focus target, not a new tab stop.
    element.setAttribute('tabindex', '-1');

    return element;
}

/**
 * Whether a control can actually be reached right now.
 *
 * A field inside an inactive tab is in the document but hidden, and focusing
 * a hidden element does nothing at all — silently.
 */
function reachable(element: HTMLElement): boolean {
    return element.closest('[hidden]') === null;
}

/**
 * How many render passes to wait for the field to become reachable.
 *
 * The tab set opens the failing tab by watching the same errors this is
 * reacting to, so the panel holding the field is revealed a render or two
 * after the errors are set — and how many depends on how the tabs are nested.
 * Waiting for the condition rather than for a fixed number of ticks is what
 * stops this from being a race that passes in a test and loses in a browser.
 */
const PASSES = 5;

/**
 * Moves focus to the first field the submit was refused for.
 *
 * "First" is first on screen, not first key of the errors object — that order
 * is whatever the server serialised, and on a form whose fields were declared
 * in a different order it points at the wrong one. The registry holds fields
 * in the order they were rendered.
 *
 * Returns the path focused, or null when nothing could be, so the caller can
 * tell the difference between "handled" and "the user is still looking at a
 * form that appears to have ignored them".
 */
export async function focusFirstInvalid(
    registry: FieldRegistry,
    errors: Record<string, string>,
    document: Document,
): Promise<string | null> {
    const invalid = registry.paths().filter((path) => path in errors);

    // An error for something never rendered — a schema-level message, or a
    // field a condition has hidden — still has to be answerable, so the
    // remaining keys follow in the order they arrived.
    const rest = Object.keys(errors).filter((path) => !invalid.includes(path));

    const order = [...invalid, ...rest];

    for (let pass = 0; pass < PASSES; pass += 1) {
        await nextTick();

        for (const path of order) {
            const id = registry.get(path)?.controlId ?? path;
            const element = document.getElementById(id);

            if (element === null || !reachable(element)) {
                continue;
            }

            const target = focusable(element);

            target?.focus();

            if (document.activeElement === target) {
                return path;
            }
        }
    }

    return null;
}
