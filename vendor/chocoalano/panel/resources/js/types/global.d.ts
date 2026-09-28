// Extend ImportMeta interface for Vite...
declare module 'vite/client' {
    interface ImportMetaEnv {
        readonly VITE_APP_NAME: string;
        [key: string]: string | boolean | undefined;
    }

    interface ImportMeta {
        readonly env: ImportMetaEnv;
        readonly glob: <T>(pattern: string) => Record<string, () => Promise<T>>;
    }
}

/*
 * This file used to also augment `@inertiajs/core`'s `InertiaConfig` with
 * every prop `SharePanelData` shares, plus three the application owns
 * (`name`, `auth`, `sidebarOpen`).
 *
 * Both halves were wrong, for the same reason. It publishes into
 * `resources/js/types/` — a directory a starter kit already owns and already
 * declares things in — and the panel's composables were written to depend on
 * the result landing, being included by the host's `tsconfig`, and merging
 * with whatever the host says about the same interface. When any of that
 * failed the type fell back to `{}` and the *application's* `vue-tsc` reported
 * fourteen errors inside files the developer never wrote.
 *
 * The panel now reads its own props through `panel/types/shared.ts`, which
 * depends on no augmentation at all, and the application's three props are
 * described only in `frontend/host/`, which never ships — because they are
 * the application's to declare.
 */
