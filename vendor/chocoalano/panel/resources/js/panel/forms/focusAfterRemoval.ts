import { nextTick } from 'vue';

/**
 * Where focus goes when a repeated entry is deleted.
 *
 * The button that was activated no longer exists, and the browser's answer to
 * that is to drop focus to the body — except it does not, quite: the DOM node
 * at that position is reused by whatever entry moved up into it, and focus
 * stays on it. So the user is left on a *different* entry's Remove button.
 * Press the key twice and the second entry goes too, without ever having been
 * chosen.
 *
 * The target is resolved by entry identity rather than by index, for the
 * reason UI-2B established: a position is not an identity, and the positions
 * have just shifted underneath.
 *
 * Deliberately narrow. This is not a focus manager; it answers one question
 * for one interaction.
 */
export type RemovalFocusTarget = {
    /** The identity of the entry to focus, or null to focus the add control. */
    entry: string | null;
};

/**
 * Which entry survives to receive focus.
 *
 * The next one, because that is where the eye already is — the list closed up
 * and something else is now under the pointer. Failing that the previous one,
 * which is the only direction left. Failing both, there is nothing to focus
 * but the control that makes a new entry.
 */
export function survivorAfterRemoval(
    identities: string[],
    removed: number,
): RemovalFocusTarget {
    const remaining = identities.filter((_, index) => index !== removed);

    if (remaining.length === 0) {
        return { entry: null };
    }

    return { entry: remaining[Math.min(removed, remaining.length - 1)] };
}

/**
 * Moves focus once the removal has rendered.
 *
 * Never onto another entry's destructive control — that is the whole point —
 * so the target is either the entry's own header toggle, which does nothing
 * worse than fold it up, or the entry container itself. A container is made
 * focusable with `-1` rather than `0`: it is a place to put focus, not a new
 * tab stop.
 */
export async function focusAfterRemoval(
    root: HTMLElement | null,
    target: RemovalFocusTarget,
    addSelector = '[data-repeat-add]',
): Promise<void> {
    if (root === null) {
        return;
    }

    await nextTick();

    if (target.entry === null) {
        root.querySelector<HTMLElement>(addSelector)?.focus();

        return;
    }

    const entry = root.querySelector<HTMLElement>(
        `[data-repeat-entry="${CSS.escape(target.entry)}"]`,
    );

    if (entry === null) {
        return;
    }

    const safe = entry.querySelector<HTMLElement>('[data-repeat-focus]');

    if (safe !== null) {
        safe.focus();

        return;
    }

    // An entry that is not collapsible has no header control of its own that
    // is safe to focus — the first button in its header may well be Remove.
    entry.setAttribute('tabindex', '-1');
    entry.focus();
}
