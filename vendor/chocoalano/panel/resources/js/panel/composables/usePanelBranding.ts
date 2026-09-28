import { computed } from 'vue';
import type { ComputedRef } from 'vue';
import { useAppearance } from '@/composables/useAppearance';
import { safeUrl } from '@/lib/utils';
import type { ResolvedAppearance } from '@/types';

export interface PanelBrandSource {
    brandLogo?: string | null;
    darkBrandLogo?: string | null;
    icon: string | null;
    darkIcon?: string | null;
}

export type UsePanelBrandingReturn = {
    iconName: ComputedRef<string | null>;
    logo: ComputedRef<string | null>;
};

function themedValue(
    light: string | null | undefined,
    dark: string | null | undefined,
    appearance: ResolvedAppearance,
): string | null {
    return appearance === 'dark' ? (dark ?? light ?? null) : (light ?? null);
}

export function themedPanelIcon(
    source: PanelBrandSource | null | undefined,
    appearance: ResolvedAppearance,
): string | null {
    return themedValue(source?.icon, source?.darkIcon, appearance);
}

export function themedPanelLogo(
    source: PanelBrandSource | null | undefined,
    appearance: ResolvedAppearance,
): string | null {
    return safeUrl(
        themedValue(source?.brandLogo, source?.darkBrandLogo, appearance),
    );
}

export function usePanelBranding(
    source: () => PanelBrandSource | null | undefined,
): UsePanelBrandingReturn {
    const { resolvedAppearance } = useAppearance();

    return {
        iconName: computed(() =>
            themedPanelIcon(source(), resolvedAppearance.value),
        ),
        logo: computed(() =>
            themedPanelLogo(source(), resolvedAppearance.value),
        ),
    };
}
