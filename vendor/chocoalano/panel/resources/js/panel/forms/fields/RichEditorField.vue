<script setup lang="ts">
import { computed, watch } from 'vue';
import { EditorContent, useEditor } from '@tiptap/vue-3';
import StarterKit from '@tiptap/starter-kit';
import FieldWrapper from '@/panel/forms/fields/FieldWrapper.vue';
import { useFieldIdentity } from '@/panel/forms/fieldIdentity';
import type { RichEditorFieldDefinition } from '@/panel/types/form';
import { useTranslator } from '@/composables/useTranslator';

const { t } = useTranslator();

/**
 * A toolbar button's face.
 *
 * Most are typographic — `B`, `H2`, `❝` — and mean the same thing in every
 * language, so they are written literally. The few that are words hold a
 * translation key instead, recognised by its dot.
 */
function toolbarLabel(label: string): string {
    return label.includes('.') ? t(label) : label;
}

const props = defineProps<{
    field: RichEditorFieldDefinition;
    modelValue: unknown;
    error?: string;
}>();

const emit = defineEmits<{ 'update:modelValue': [value: string] }>();

/**
 * A ProseMirror document, edited through Tiptap, serialized as HTML.
 *
 * This was a `contenteditable` driven by `document.execCommand`, and the
 * reason it no longer is has nothing to do with the deprecation notice. A
 * browser's own editing commands are a *suggestion*: what `bold` produces
 * differs between engines, `formatBlock` will happily nest a heading inside a
 * list item, and pasting from a word processor put whatever markup the
 * clipboard carried straight into the value. There was no document model, so
 * there was nothing to be wrong about — only markup, and no way to say which
 * markup was legal.
 *
 * Tiptap has a schema, and that is the whole of the improvement: only the
 * nodes and marks configured below can exist in the document, whatever a user
 * types or pastes. Paste is parsed against that schema rather than inserted,
 * so the `<span style>` soup a word processor sends arrives as the emphasis it
 * was meant to be.
 *
 * What it produces is still never trusted. `RichEditor::sanitize()` strips the
 * HTML to an allowlist on the way in, so the schema below is the *editor's*
 * answer and the server's list remains the actual one. The two are kept
 * deliberately aligned — see `heading` — but only one of them is a control.
 */

/**
 * The ids the label and the descriptions use.
 *
 * Every other field takes these from `FieldWrapper`'s slot. This one cannot:
 * the element they belong on is the `contenteditable` ProseMirror creates, and
 * the only way to put attributes on that is `editorProps.attributes` — which
 * is read in `setup`, where a slot's props do not exist yet. So the same
 * derivation is called directly. It is a pure function of the injected scope
 * and the field name, so it cannot disagree with the wrapper's copy.
 */
const identity = useFieldIdentity(() => props.field.name, {
    helper: () =>
        props.field.helperText !== null && props.field.helperText !== '',
    error: () => props.error !== undefined && props.error !== '',
});

const invalid = computed(() => props.error !== undefined && props.error !== '');

function html(): string {
    return typeof props.modelValue === 'string' ? props.modelValue : '';
}

/**
 * What the ProseMirror element itself carries.
 *
 * Recomputed rather than set once, because `aria-describedby` and
 * `aria-invalid` change when the server refuses the field — and a control that
 * says `aria-invalid` without naming the sentence explaining the refusal is
 * the U01 bug this project already fixed everywhere else.
 */
const editorAttributes = computed<Record<string, string>>(() => {
    const attributes: Record<string, string> = {
        id: identity.value.controlId,
        role: 'textbox',
        'aria-multiline': 'true',
        'aria-label': props.field.label,
        class: 'panel-prose min-h-40 max-w-none bg-background p-3 text-sm outline-none focus-visible:ring-3 focus-visible:ring-ring/50 focus-visible:ring-inset',
    };

    if (identity.value.describedBy !== undefined) {
        attributes['aria-describedby'] = identity.value.describedBy;
    }

    if (invalid.value) {
        attributes['aria-invalid'] = 'true';
    }

    return attributes;
});

const editor = useEditor({
    content: html(),
    editable: !props.field.disabled,
    editorProps: { attributes: editorAttributes.value },
    extensions: [
        StarterKit.configure({
            // Only the levels `allowedTags()` lets through by default. A
            // heading the server strips is formatting a user applies and
            // silently loses.
            heading: { levels: [2, 3, 4] },
            link: {
                // A link in an editor is text being edited, not navigation.
                openOnClick: false,
                // Tiptap refuses `javascript:` and friends itself. That is a
                // convenience, not the control — `SafeUrl` on the server is.
                autolink: true,
            },
        }),
    ],
    onUpdate: ({ editor: instance }) => {
        // An empty document serializes as `<p></p>`, which is markup and not
        // content, and must not count towards a required field.
        emit('update:modelValue', instance.isEmpty ? '' : instance.getHTML());
    },
});

/**
 * Written into the document only when the two have actually diverged.
 *
 * Replacing the content resets the selection, so echoing back the value the
 * editor just emitted would put the caret at the start on every keystroke.
 */
watch(
    () => props.modelValue,
    () => {
        const instance = editor.value;

        if (instance === undefined || instance.getHTML() === html()) {
            return;
        }

        instance.commands.setContent(html(), { emitUpdate: false });
    },
);

watch(
    () => props.field.disabled,
    (disabled) => editor.value?.setEditable(!disabled),
);

watch(editorAttributes, (attributes) =>
    editor.value?.setOptions({ editorProps: { attributes } }),
);

/**
 * The commands a toolbar button maps to, keyed by the names the schema uses.
 * A name not in here draws no button, exactly as an unregistered icon renders
 * nothing.
 *
 * `active` is what the button reports as its pressed state. Only the commands
 * that toggle something have one — `undo` is an action, and `aria-pressed` on
 * it would claim the editor is "currently undone".
 */
type ToolbarCommand = {
    label: string;
    run: () => void;
    active?: () => boolean;
};

const COMMANDS: Record<string, ToolbarCommand> = {
    bold: {
        label: 'B',
        run: () => chain()?.toggleBold().run(),
        active: () => isActive('bold'),
    },
    italic: {
        label: 'I',
        run: () => chain()?.toggleItalic().run(),
        active: () => isActive('italic'),
    },
    strike: {
        label: 'S',
        run: () => chain()?.toggleStrike().run(),
        active: () => isActive('strike'),
    },
    underline: {
        label: 'U',
        run: () => chain()?.toggleUnderline().run(),
        active: () => isActive('underline'),
    },
    h2: {
        label: 'H2',
        run: () => chain()?.toggleHeading({ level: 2 }).run(),
        active: () => isActive('heading', { level: 2 }),
    },
    h3: {
        label: 'H3',
        run: () => chain()?.toggleHeading({ level: 3 }).run(),
        active: () => isActive('heading', { level: 3 }),
    },
    blockquote: {
        label: '❝',
        run: () => chain()?.toggleBlockquote().run(),
        active: () => isActive('blockquote'),
    },
    bulletList: {
        label: 'forms.editor_bullet_list',
        run: () => chain()?.toggleBulletList().run(),
        active: () => isActive('bulletList'),
    },
    orderedList: {
        label: 'forms.editor_ordered_list',
        run: () => chain()?.toggleOrderedList().run(),
        active: () => isActive('orderedList'),
    },
    link: {
        label: 'forms.editor_link',
        run: () => link(),
        active: () => isActive('link'),
    },
    undo: { label: '↶', run: () => chain()?.undo().run() },
    redo: { label: '↷', run: () => chain()?.redo().run() },
};

/**
 * A command chain that starts by putting the caret back.
 *
 * Clicking a toolbar button moves focus to the button, and a command applies
 * to the selection — so without `focus()` the first press after a click
 * formats nothing.
 */
function chain() {
    if (props.field.disabled) {
        return undefined;
    }

    return editor.value?.chain().focus();
}

function isActive(name: string, attributes?: Record<string, unknown>): boolean {
    return editor.value?.isActive(name, attributes) ?? false;
}

/** Only the commands that toggle something have a pressed state to report. */
function pressedState(name: string): boolean | undefined {
    return COMMANDS[name]?.active?.();
}

function link(): void {
    const instance = editor.value;

    if (instance === undefined || props.field.disabled) {
        return;
    }

    // Pre-filled when the caret is already inside a link, so the button edits
    // one rather than only ever making one.
    const current = instance.getAttributes('link').href;
    const url = window.prompt(
        t('forms.link_url'),
        typeof current === 'string' ? current : '',
    );

    // A cancelled prompt leaves the selection alone rather than wrapping it in
    // a link to nowhere. A prompt answered with nothing removes the link the
    // caret is in, which is the only way to take one off again.
    if (url === null) {
        return;
    }

    if (url.trim() === '') {
        instance.chain().focus().unsetLink().run();

        return;
    }

    instance.chain().focus().setLink({ href: url.trim() }).run();
}
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
        <!--
            Two indicators, answering two questions.

            The border says *which editor holds focus* and changes on
            `:focus-within`, so it is right whether the caret arrived by click
            or by Tab, and it is restrained — a colour, not a glow. The ring
            drawn on the editable region itself says *this is where the
            keyboard is*, and is `:focus-visible` only, so clicking a toolbar
            button does not leave the whole field lit up.

            An error keeps its own border: a field that is both focused and
            wrong should not stop looking wrong, so the destructive colour
            wins and the ring still draws around it.
        -->
        <div
            data-slot="rich-editor"
            class="overflow-hidden rounded-md border border-input transition-colors focus-within:border-ring"
            :class="
                error
                    ? 'border-destructive focus-within:border-destructive'
                    : ''
            "
        >
            <div
                v-if="field.toolbar.length > 0"
                class="flex flex-wrap gap-0.5 border-b border-input bg-muted/40 p-1"
            >
                <template v-for="button in field.toolbar" :key="button">
                    <button
                        v-if="COMMANDS[button]"
                        type="button"
                        class="rounded px-2 py-1 text-xs font-medium text-muted-foreground hover:bg-accent hover:text-foreground focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none disabled:cursor-not-allowed disabled:opacity-50 aria-pressed:bg-accent aria-pressed:text-foreground aria-pressed:ring-1 aria-pressed:ring-border"
                        :disabled="field.disabled"
                        :aria-label="button"
                        :aria-pressed="pressedState(button)"
                        @click="COMMANDS[button].run()"
                    >
                        {{ toolbarLabel(COMMANDS[button].label) }}
                    </button>
                </template>
            </div>

            <EditorContent
                :editor="editor"
                :class="field.disabled ? 'cursor-not-allowed opacity-50' : ''"
            />
        </div>
    </FieldWrapper>
</template>
