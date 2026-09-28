import { onBeforeUnmount, onMounted, watch } from 'vue';

/**
 * Puts the field's identity on an element a third-party editor owns.
 *
 * Every field in this form layer carries the same four things: an `id` the
 * label points at, an accessible name, `aria-describedby` naming the helper
 * and the error, and `aria-invalid` when the server refused it. For an
 * ordinary control those are bound in the template and that is the end of it.
 *
 * Two of the fields no longer own their control. Markdown is edited in a
 * CodeMirror view that `md-editor-v3` constructs, and code in a Monaco editor
 * that mounts after its module has loaded — in both cases the element that
 * actually takes the keyboard is created for us, after the component has
 * rendered, and there is no prop for putting arbitrary attributes on it.
 *
 * So the attributes are written to it once it exists. A `MutationObserver` is
 * what makes "once it exists" reliable: an editor that mounts on the next tick
 * and one that mounts after a dynamic import both arrive as a subtree
 * insertion, and waiting a fixed number of frames for either is a guess that
 * is wrong on a slow machine.
 */
export function useEditorAttributes(
    container: () => HTMLElement | null,
    /** The focusable element inside it — `.cm-content`, `textarea`. */
    selector: string,
    attributes: () => Record<string, string | undefined>,
): void {
    let observer: MutationObserver | null = null;

    function target(): HTMLElement | null {
        return container()?.querySelector<HTMLElement>(selector) ?? null;
    }

    function apply(element: HTMLElement): void {
        for (const [name, value] of Object.entries(attributes())) {
            if (value === undefined) {
                element.removeAttribute(name);
            } else {
                element.setAttribute(name, value);
            }
        }
    }

    onMounted(() => {
        const root = container();
        const found = target();

        if (found !== null) {
            apply(found);
        }

        if (root === null) {
            return;
        }

        /**
         * Left running rather than disconnected once the element appears,
         * because these editors do sometimes rebuild it — `md-editor-v3`
         * remounts its CodeMirror view for some option changes, and a rebuilt
         * element comes back with none of this on it. Disconnecting would leave
         * a label pointing at an id that no longer exists, silently, and only
         * for whoever changed a theme while the form was open.
         *
         * The guard below is what makes that affordable: a keystroke mutates
         * the editor's children constantly, and every one of those batches
         * costs a `querySelector` and one attribute read before returning.
         */
        observer = new MutationObserver(() => {
            const element = target();

            if (element === null || element.id === identity()) {
                return;
            }

            apply(element);
        });

        observer.observe(root, { childList: true, subtree: true });
    });

    /** What `apply()` would write as the element's `id`, or `''` for none. */
    function identity(): string {
        return attributes().id ?? '';
    }

    onBeforeUnmount(() => {
        observer?.disconnect();
        observer = null;
    });

    // An error arriving is the case that matters: `aria-invalid` and the id of
    // the sentence explaining the refusal both change after the first write.
    watch(attributes, () => {
        const element = target();

        if (element !== null) {
            apply(element);
        }
    });
}
