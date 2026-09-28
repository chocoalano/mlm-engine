import { watchEffect } from 'vue';
import { panelSharedProps } from '@/panel/types/shared';
import { PANEL_CONTRACT_VERSION } from '@/panel/contract';

/**
 * Says so when this frontend is older than the backend serving it.
 *
 * The panel's components are published into the application rather than
 * imported from the package, so after a `composer update` the PHP is new and
 * the components are whatever was published last. Most of that drift is
 * invisible and harmless. The part that is not — a prop nothing reads, an
 * endpoint nothing calls, a payload the narrowing rejects and replaces with
 * "leave what was on screen" — fails *silently*, and reads as "the panel is
 * broken" with nothing in any log to say why.
 *
 * So it is said once, in the console, in development only.
 *
 * ## Why only a console warning
 *
 * A mismatch is an explanation for something else behaving oddly, not a fault
 * of its own. The panel still works, mostly; the older the frontend the more
 * of the newer half is simply absent. Refusing to render would turn a subtle
 * problem into a total one, and doing it in production would take an
 * application that mostly works offline over a warning nobody was reading.
 *
 * Ahead is not warned about at all: it only happens while somebody is
 * developing the package itself, and is not a state updating can produce.
 */
export function useContractCheck(): void {
    if (!import.meta.env.DEV) {
        return;
    }

    const props = panelSharedProps();

    watchEffect(() => {
        const contract = props.value.contract;

        if (
            contract === undefined ||
            PANEL_CONTRACT_VERSION >= contract.expected
        ) {
            return;
        }

        console.warn(
            `[panda-panel] The published frontend speaks contract v${PANEL_CONTRACT_VERSION}, ` +
                `but this backend speaks v${contract.expected}. Features added since ` +
                `v${PANEL_CONTRACT_VERSION} will be missing or silently inert. ` +
                `Run \`${contract.remediation}\` to republish the components.`,
        );
    });
}
