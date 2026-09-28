/**
 * Mirrors `PandaPanel\Support\Breadcrumb`.
 *
 * Labels are plain text and are rendered as text, never as markup.
 */
export interface PanelBreadcrumbItem {
    label: string;
    href: string | null;
    current: boolean;
}
