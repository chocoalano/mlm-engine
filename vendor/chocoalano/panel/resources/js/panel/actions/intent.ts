import type { ActionDefinition } from '@/panel/types/action';

/**
 * Whether an action has something to say before it runs.
 *
 * Three declarations mean "hold this until the user has said something more",
 * and every entry point that can start an action has to honour all three or
 * the one it forgets becomes a button that fires on the first click:
 *
 *   - a form, which has values to collect;
 *   - a confirmation, which has a question to ask;
 *   - a modal, which has copy the developer wrote to be read.
 *
 * The third was the one that got forgotten. `modalHeading()` and
 * `modalDescription()` were accepted by the PHP, serialized into the payload,
 * and then ignored by every composable — so an action declaring
 *
 *     ->modalHeading('Approve machine?')
 *     ->modalDescription('This grants access immediately.')
 *
 * ran its handler on the first click and never showed either sentence. The
 * API accepted a declaration it could not honour, which is worse than not
 * offering it: the developer had every reason to believe the guard was there.
 *
 * Read from the server's own answer rather than guessed at. `modal` is
 * non-null only when something actually configured it — `Action::getModal()`
 * creates it lazily — so a plain action carries null here and still runs
 * immediately, which is what a plain action is for.
 */
export function opensModal(action: ActionDefinition): boolean {
    return (
        action.type === 'form' ||
        action.confirmation !== null ||
        action.modal !== null
    );
}
