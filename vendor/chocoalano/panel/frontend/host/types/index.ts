/**
 * The starter kit's shared types — `@/types`.
 *
 * Three of them, all about the application shell rather than the panel: the
 * appearance preference the panel's own settings page writes, and the shell
 * variant `AppShell` and `AppContent` lay out against.
 */
export type Appearance = 'light' | 'dark' | 'system';

export type ResolvedAppearance = 'light' | 'dark';

export type AppVariant = 'header' | 'sidebar';
