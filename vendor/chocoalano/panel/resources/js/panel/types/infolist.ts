/**
 * Mirrors `PandaPanel\Infolists\*`.
 *
 * A discriminated union on `type`, so the renderer's exhaustive switch turns
 * a new PHP entry type without a Vue renderer into a compile error. Adding an
 * entry type means adding both sides.
 */
import type { BadgeColorName } from '@/panel/palette';
import type { ActionDefinition } from '@/panel/types/action';
import type { CodeLanguage } from '@/panel/types/form';

export type EntryType =
    | 'text'
    | 'badge'
    | 'boolean'
    | 'datetime'
    | 'key-value'
    | 'icon'
    | 'image'
    | 'color'
    | 'code'
    | 'repeatable'
    | 'custom';

interface BaseEntryDefinition {
    component: 'entry';
    name: string;
    label: string;
    placeholder: string | null;
    helperText: string | null;
    /** Columns of the container this entry takes, or the whole row. */
    columnSpan: number | 'full';
    /**
     * An operation offered beside the value. Null for an entry that declares
     * none, and for one the user may not run — an action they cannot perform
     * is absent rather than a button that answers 403.
     */
    action: ActionDefinition | null;
}

export interface TextEntryDefinition extends BaseEntryDefinition {
    type: 'text';
    value: string | null;
    prose: boolean;
}

export interface BadgeEntryDefinition extends BaseEntryDefinition {
    type: 'badge';
    value: { label: string; color: BadgeColorName } | null;
}

export interface BooleanEntryDefinition extends BaseEntryDefinition {
    type: 'boolean';
    value: boolean;
    trueLabel: string;
    falseLabel: string;
}

export interface DateTimeEntryDefinition extends BaseEntryDefinition {
    type: 'datetime';
    value: string | null;
}

export interface KeyValueEntryDefinition extends BaseEntryDefinition {
    type: 'key-value';
    value: Array<{ key: string; value: string }>;
}

export interface IconEntryDefinition extends BaseEntryDefinition {
    type: 'icon';
    value: { icon: string; color: BadgeColorName; label: string } | null;
}

export interface ImageEntryDefinition extends BaseEntryDefinition {
    type: 'image';
    /** Resolved on the server; null when the disk serves no public URL. */
    value: string | null;
    size: number;
    circular: boolean;
}

export interface ColorEntryDefinition extends BaseEntryDefinition {
    type: 'color';
    value: string | null;
    copyable: boolean;
}

export interface CodeEntryDefinition extends BaseEntryDefinition {
    type: 'code';
    value: string | null;
    language: CodeLanguage;
    copyable: boolean;
}

export interface RepeatableEntryDefinition extends BaseEntryDefinition {
    type: 'repeatable';
    value: Array<{
        label: string | null;
        schema: InfolistComponentDefinition[];
    }>;
    columns: number;
}

export interface CustomEntryDefinition extends BaseEntryDefinition {
    type: 'custom';
    value: unknown;
    componentName: string;
    config: Record<string, unknown>;
}

export type EntryDefinition =
    | TextEntryDefinition
    | BadgeEntryDefinition
    | BooleanEntryDefinition
    | DateTimeEntryDefinition
    | KeyValueEntryDefinition
    | IconEntryDefinition
    | ImageEntryDefinition
    | ColorEntryDefinition
    | CodeEntryDefinition
    | RepeatableEntryDefinition
    | CustomEntryDefinition;

export interface InfolistSectionDefinition {
    component: 'section';
    heading: string;
    description: string | null;
    columns: number;
    schema: InfolistComponentDefinition[];
    headerActions: ActionDefinition[];
}

export interface InfolistGridDefinition {
    component: 'grid';
    columns: number;
    schema: InfolistComponentDefinition[];
}

export interface InfolistTabDefinition {
    component: 'tab';
    label: string;
    key: string;
    icon: string | null;
    badge: string | null;
    columns: number;
    schema: InfolistComponentDefinition[];
}

export interface InfolistTabsDefinition {
    component: 'tabs';
    persistTab: boolean;
    tabs: InfolistTabDefinition[];
}

export type InfolistComponentDefinition =
    | EntryDefinition
    | InfolistSectionDefinition
    | InfolistGridDefinition
    | InfolistTabsDefinition;

export interface InfolistDefinition {
    columns: number;
    schema: InfolistComponentDefinition[];
    /** Operations offered for the record as a whole, above the infolist. */
    actions: ActionDefinition[];
}
