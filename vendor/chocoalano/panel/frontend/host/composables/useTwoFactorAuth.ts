/**
 * The starter kit's two-factor composable.
 *
 * Only the two members the panel's `ManageTwoFactor` destructures are
 * modelled: whether a setup payload — QR code, secret — has been fetched, and
 * the teardown that drops it when the component goes away.
 */
import { ref } from 'vue';
import type { Ref } from 'vue';

export function useTwoFactorAuth(): {
    hasSetupData: Ref<boolean>;
    clearTwoFactorAuthData: () => void;
} {
    const hasSetupData = ref(false);

    return {
        hasSetupData,
        clearTwoFactorAuthData: (): void => {
            hasSetupData.value = false;
        },
    };
}
