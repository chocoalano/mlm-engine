<script setup lang="ts">
import { computed, ref } from 'vue';
import { MdEditor } from 'md-editor-v3';
import type { ToolbarNames } from 'md-editor-v3';
import 'md-editor-v3/lib/style.css';
import FieldWrapper from '@/panel/forms/fields/FieldWrapper.vue';
import { useEditorAttributes } from '@/panel/forms/fields/editorAttributes';
import { useFieldIdentity } from '@/panel/forms/fieldIdentity';
import { useColorScheme } from '@/panel/composables/useColorScheme';
import type { MarkdownEditorFieldDefinition } from '@/panel/types/form';

const props = defineProps<{
    field: MarkdownEditorFieldDefinition;
    modelValue: unknown;
    error?: string;
}>();

const emit = defineEmits<{ 'update:modelValue': [value: string] }>();

/**
 * Markdown is stored as Markdown, and this is `md-editor-v3` editing it.
 *
 * What it replaces was a textarea, a table of strings to wrap the selection
 * in, and a preview rendered by a hand-written Markdown subset. Each of those
 * three was a small lie. The toolbar inserted syntax without understanding it,
 * so pressing **B** twice produced `****text****`; the textarea had no idea it
 * held Markdown, so a list continued only if the user typed the next marker
 * themselves; and the preview implemented headings, quotes, fenced code and
 * the inline marks the toolbar knew about, which meant a table, a footnote or
 * a reference link — all valid Markdown, all stored perfectly well — rendered
 * as the literal characters they were typed as. The button showed something
 * true about the *toolbar*, not about the value.
 *
 * `md-editor-v3` parses. The editing surface is CodeMirror with a Markdown
 * grammar, so a list continues and a toggle toggles, and the preview is
 * `markdown-it`, so what it shows is what a Markdown renderer produces.
 *
 * Still nothing is sanitized and still nothing is converted: what is typed is
 * what is submitted. The preview is a view of the value and never a step on
 * the way to storing it, which is the same guarantee the old one gave.
 */

/**
 * Deliberately not the editor's whole feature set.
 *
 * `md-editor-v3` fetches highlight.js, Prettier, Mermaid, KaTeX, ECharts and
 * Cropper from unpkg the first time a feature needs one. An admin panel that
 * reaches out to a CDN mid-edit is a panel that behaves differently on a
 * network that blocks it, under a strict `Content-Security-Policy`, or on an
 * air-gapped deployment — and the failure lands on a user in the middle of
 * writing something. So every one of those features is off, and nothing here
 * loads anything at runtime that the application did not build.
 *
 * The cost is honest and small: fenced code in the preview is monospaced
 * rather than colourised, and there is no reformat button. Both of those are
 * conveniences. Neither is what the field is for.
 */
const OFFLINE = {
    noHighlight: true,
    noPrettier: true,
    noMermaid: true,
    noKatex: true,
    noEcharts: true,
    noUploadImg: true,
    noImgZoomIn: true,
} as const;

/**
 * The schema's button names, mapped to what the editor calls them.
 *
 * The names on the left are `MarkdownEditor::toolbar()`'s and they do not
 * change: a form that asked for `bulletList` keeps asking for `bulletList`.
 * A name not in here draws no button, which is what it did before.
 */
const TOOLBAR: Record<string, ToolbarNames> = {
    bold: 'bold',
    italic: 'italic',
    strike: 'strikeThrough',
    underline: 'underline',
    link: 'link',
    heading: 'title',
    bulletList: 'unorderedList',
    orderedList: 'orderedList',
    taskList: 'task',
    blockquote: 'quote',
    code: 'codeRow',
    codeBlock: 'code',
    table: 'table',
    undo: 'revoke',
    redo: 'next',
    divider: '-',
    preview: 'preview',
};

const toolbars = computed<ToolbarNames[]>(() =>
    props.field.toolbar
        .map((button) => TOOLBAR[button])
        .filter((button): button is ToolbarNames => button !== undefined),
);

const text = computed(() =>
    typeof props.modelValue === 'string' ? props.modelValue : '',
);

/**
 * The same identity every other field carries, derived here rather than taken
 * from `FieldWrapper`'s slot: the element it belongs on is the CodeMirror
 * content div, which the editor creates for itself. See `editorAttributes`.
 */
const identity = useFieldIdentity(() => props.field.name, {
    helper: () =>
        props.field.helperText !== null && props.field.helperText !== '',
    error: () => props.error !== undefined && props.error !== '',
});

const container = ref<HTMLElement | null>(null);

useEditorAttributes(
    () => container.value,
    // CodeMirror's own editable element. It already says `role="textbox"` and
    // `aria-multiline="true"`; what it cannot know is what this field is
    // called or what the server said about it.
    '.cm-content',
    () => ({
        id: identity.value.controlId,
        'aria-label': props.field.label,
        'aria-describedby': identity.value.describedBy,
        'aria-invalid':
            props.error !== undefined && props.error !== ''
                ? 'true'
                : undefined,
    }),
);

const scheme = useColorScheme();

/**
 * `rows` still means rows.
 *
 * The editor is a sized box rather than a growing textarea, so the number a
 * schema gave has to become a height. One line of the editor's own text is
 * `1.5rem`; the rest is the toolbar strip above it.
 */
const height = computed(() => `calc(${props.field.rows} * 1.5rem + 3rem)`);
</script>

<template>
    <FieldWrapper
        :name="field.name"
        :inline-label="field.inlineLabel"
        :label="field.label"
        :required="field.required"
        :helper-text="field.helperText"
        :error="error"
    >
        <div
            ref="container"
            data-slot="markdown-editor"
            class="panel-markdown-editor overflow-hidden rounded-md border border-input transition-colors focus-within:border-ring"
            :class="
                error
                    ? 'border-destructive focus-within:border-destructive'
                    : ''
            "
        >
            <MdEditor
                :model-value="text"
                :toolbars="toolbars"
                :footers="[]"
                :theme="scheme"
                language="en-US"
                :style="{ height }"
                :disabled="field.disabled"
                :placeholder="field.placeholder ?? undefined"
                :max-length="field.maxLength ?? undefined"
                :tab-width="4"
                v-bind="OFFLINE"
                @update:model-value="
                    (value: string) => emit('update:modelValue', value)
                "
            />
        </div>
    </FieldWrapper>
</template>

<!--
    The only `<style>` block in this frontend, and the reason is specific.

    `md-editor-v3` is themed through CSS custom properties — one set on
    `.md-editor` for its chrome, another on `.md-editor .md-editor-preview` for
    the rendered content — and their defaults are literal hex colours: a white
    background, a `#2d8cf0` link, a `#e6e6e6` border. In a panel whose
    `--background` is not `#fff` that is a stark white rectangle in the middle
    of the form, and in a re-themed panel it is somebody else's brand colour on
    every link in the preview.

    So the properties are mapped to the panel's tokens. This is the library's
    own extension point rather than a fight with its selectors, and it follows a
    re-themed panel and a dark one for free — the tokens already do.

    Scoped rather than added to `panda-panel.css`, for two reasons. That
    stylesheet is published into an application, and a bare `.md-editor` rule in
    it would restyle an editor the application mounted for its own purposes.
    And the library's own rules are two classes deep, so an override has to be
    three; `:deep()` inside a scoped block is exactly three and says why.

    Tailwind cannot express any of this: there is no utility for "set a custom
    property on a descendant a library created".
-->
<style scoped>
.panel-markdown-editor :deep(.md-editor) {
    --md-color: var(--foreground);
    --md-hover-color: var(--foreground);
    --md-bk-color: var(--background);
    --md-bk-color-outstand: var(--muted);
    --md-bk-hover-color: var(--accent);
    --md-border-color: var(--border);
    --md-border-hover-color: var(--border);
    --md-border-active-color: var(--ring);
    --md-scrollbar-bg-color: transparent;
    --md-scrollbar-thumb-color: var(--border);
    --md-scrollbar-thumb-hover-color: var(--muted-foreground);
    --md-scrollbar-thumb-active-color: var(--muted-foreground);
}

.panel-markdown-editor :deep(.md-editor-preview) {
    --md-theme-color: var(--foreground);
    --md-theme-color-hover: var(--accent);
    --md-theme-bg-color: var(--background);
    --md-theme-bg-color-inset: var(--muted);
    --md-theme-border-color: var(--border);
    --md-theme-border-color-inset: var(--border);
    --md-theme-border-color-reverse: var(--muted-foreground);
    --md-theme-link-color: var(--primary);
    --md-theme-link-hover-color: var(--primary);
    --md-theme-heading-color: var(--foreground);
    --md-theme-quote-color: var(--muted-foreground);
    --md-theme-quote-bg-color: transparent;
    --md-theme-quote-border: 2px solid var(--border);
    --md-theme-table-td-border-color: var(--border);
    --md-theme-table-tr-bg-color: var(--background);
    --md-theme-table-stripe-color: var(--muted);
    --md-theme-code-inline-color: var(--foreground);
    --md-theme-code-inline-bg-color: var(--muted);
    --md-theme-code-block-color: var(--foreground);
    --md-theme-code-block-bg-color: var(--muted);
    --md-theme-code-before-bg-color: var(--muted);
    --md-theme-code-line-number-color: var(--muted-foreground);
    --md-theme-code-active-color: var(--primary);
    --md-theme-code-copy-tips-color: var(--popover-foreground);
    --md-theme-code-copy-tips-bg-color: var(--popover);
    --md-theme-radius-s: calc(var(--radius) - 4px);
    --md-theme-radius-m: calc(var(--radius) - 2px);
}

/*
 * A link distinguished only by hue is a link somebody with a colour vision
 * deficiency cannot find in a paragraph — the same reasoning as `.panel-prose`
 * in the package stylesheet, and the one place the library's theme is not
 * merely recoloured but corrected.
 */
.panel-markdown-editor :deep(.md-editor-preview a) {
    text-decoration: underline;
    text-underline-offset: 2px;
}
</style>
