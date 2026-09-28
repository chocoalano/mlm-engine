import type { Auth } from '@/types/auth';

/**
 * The application's own shared props — the stand-in for what a starter kit's
 * `resources/js/types/` already declares.
 *
 * Part of the host seam, so it never ships. These three keys belong to the
 * application: `HandleInertiaRequests` shares them, the application's auth
 * screens read them, and a package that declared them would be describing
 * somebody else's contract — and would collide with the declaration the
 * starter kit already has.
 *
 * Deliberately no panel keys. `SharePanelData`'s props are reached through
 * `panel/types/shared.ts` instead, which needs no augmentation. That is the
 * invariant `it reads panel props through one accessor` in
 * `FrontendContractTest` exists to hold: if a panel file ever reads
 * `usePage().props.panel` directly it will type-check here, against this
 * stub, and fail in every real application.
 */
declare module '@inertiajs/core' {
    export interface InertiaConfig {
        sharedPageProps: {
            name: string;
            auth: Auth;
            sidebarOpen: boolean;
            [key: string]: unknown;
        };
    }
}
