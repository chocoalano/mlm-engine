<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import { VueMonacoEditor } from '@guolao/vue-monaco-editor';
import { Textarea } from '@/components/ui/textarea';
import FieldWrapper from '@/panel/forms/fields/FieldWrapper.vue';
import { useEditorAttributes } from '@/panel/forms/fields/editorAttributes';
import { useFieldIdentity } from '@/panel/forms/fieldIdentity';
import { useColorScheme } from '@/panel/composables/useColorScheme';
import type {
    CodeEditorFieldDefinition,
    CodeLanguage,
} from '@/panel/types/form';
import { useTranslator } from '@/composables/useTranslator';

const { t } = useTranslator();

/**
 * A language's name, translated only where it has one.
 *
 * `JSON`, `HTML`, `PHP` are names rather than words: they read the same in
 * every language, so `LANGUAGE_LABELS` holds them literally and only the
 * entries that are actually English — "Plain text" — hold a translation key.
 * A key is recognised by its dot; anything else is passed through.
 */
function languageLabel(language: string): string {
    const label = LANGUAGE_LABELS[language] ?? language;

    return label.includes('.') ? t(label) : label;
}

const props = defineProps<{
    field: CodeEditorFieldDefinition;
    modelValue: unknown;
    error?: string;
}>();

const emit = defineEmits<{ 'update:modelValue': [value: string] }>();

/**
 * Monaco — the editor from VS Code — reading the same string as before.
 *
 * What it replaces was a textarea with a monospace face and a `Tab` handler,
 * and the reason that was not enough is not decoration. A textarea cannot tell
 * a string from a key, so `settings` was edited without ever being told it did
 * not parse until the server refused the whole form; the four spaces `Tab`
 * inserted were four spaces wherever the caret happened to be, indentation or
 * not; there was no bracket matching, no selection of a block, no way to find
 * anything in a long document. Every one of those is the difference between
 * looking at code and editing it.
 *
 * Monaco is bundled rather than fetched — see `monacoBundle`, which is also the
 * chunk boundary that keeps it out of the main bundle — and it is `import()`ed
 * on mount, so the textarea below is what a code field is until it arrives.
 * That fallback is not a loading state only: if the chunk fails to load, the
 * field stays editable and the value stays submittable, because a form that
 * cannot be filled in is worse than a form with a plain box in it.
 */
const LANGUAGE_LABELS: Record<string, string> = {
    plain: 'forms.plain_text',
    json: 'JSON',
    html: 'HTML',
    css: 'CSS',
    javascript: 'JavaScript',
    php: 'PHP',
    sql: 'SQL',
    yaml: 'YAML',
    markdown: 'Markdown',
};

/**
 * What each `CodeLanguage` is called inside Monaco.
 *
 * Only `plain` differs, and it has to: Monaco's language for "no grammar" is
 * `plaintext`, and an unregistered id is not an error — it is an editor that
 * silently highlights nothing, which looks exactly like a grammar that is
 * missing. The enum is a closed set on the PHP side precisely so this map can
 * be exhaustive.
 */
const MONACO_LANGUAGES: Record<CodeLanguage, string> = {
    plain: 'plaintext',
    json: 'json',
    html: 'html',
    css: 'css',
    javascript: 'javascript',
    php: 'php',
    sql: 'sql',
    yaml: 'yaml',
    markdown: 'markdown',
};

const text = computed(() =>
    typeof props.modelValue === 'string' ? props.modelValue : '',
);

const lineCount = computed(() => Math.max(text.value.split('\n').length, 1));

const identity = useFieldIdentity(() => props.field.name, {
    helper: () =>
        props.field.helperText !== null && props.field.helperText !== '',
    error: () => props.error !== undefined && props.error !== '',
});

const invalid = computed(() => props.error !== undefined && props.error !== '');

const container = ref<HTMLElement | null>(null);

/**
 * Monaco keeps its own hidden input, and that is the element the keyboard
 * actually goes to — so it is the element the label has to point at and the one
 * `aria-describedby` has to hang off. Monaco names it itself through
 * `ariaLabel`; the rest is this field's identity and has to be written on.
 *
 * Two selectors because there are two implementations. Where the browser
 * supports `EditContext`, Monaco uses a `div.native-edit-context`; everywhere
 * else it keeps the `textarea.inputarea` it has always used. Matching only one
 * of them is a label pointing at nothing on half the browsers in use.
 */
useEditorAttributes(
    () => container.value,
    '.native-edit-context, textarea.inputarea',
    () => ({
        id: identity.value.controlId,
        'aria-describedby': identity.value.describedBy,
        'aria-invalid': invalid.value ? 'true' : undefined,
    }),
);

/**
 * False until the editor's chunk has loaded — and false for good if it cannot
 * be loaded at all.
 *
 * Two situations, one flag, on purpose: both mean "the textarea below is the
 * control", and the field behaves identically in each. The console message is
 * for whoever has to work out why a panel behind a proxy has a plain box where
 * the code editor should be.
 */
const monacoReady = ref(false);

onMounted(async () => {
    try {
        const { configureMonaco } = await import('@/panel/forms/monacoBundle');

        configureMonaco();
        monacoReady.value = true;
    } catch (error) {
        console.error('The code editor could not be loaded.', error);
    }
});

const scheme = useColorScheme();

/**
 * `rows` still means rows.
 *
 * Monaco is absolutely positioned inside a box it does not size, so the number
 * a schema gave has to become a height. `19px` is Monaco's own default line
 * height at the font size set below.
 */
const height = computed(() => `${props.field.rows * 19 + 16}px`);

/**
 * Deliberately not an IDE.
 *
 * This is a control in a form, and several of Monaco's defaults assume it is a
 * window in an editor instead. A minimap of a twelve-line snippet is noise; a
 * sticky scroll header on a JSON blob is noise; `Ctrl+F` inside a form field is
 * useful and its own overlay is not, at this size. What is kept is everything
 * that is about the text: the gutter, bracket matching, folding, and the
 * validation a language service provides.
 *
 * `tabSize: 4` and `insertSpaces: true` keep the one promise the textarea made
 * — Tab is four spaces — and now keep it as indentation rather than as four
 * characters wherever the caret was.
 */
const options = computed(() => ({
    ariaLabel: props.field.label,
    automaticLayout: true,
    fontSize: 13,
    tabSize: 4,
    insertSpaces: true,
    // Escape then Tab is still the way out of a code editor, which is the
    // convention Monaco is itself the origin of.
    tabFocusMode: false,
    minimap: { enabled: false },
    overviewRulerLanes: 0,
    padding: { top: 8, bottom: 8 },
    readOnly: props.field.disabled,
    renderLineHighlight: 'none' as const,
    scrollBeyondLastLine: false,
    scrollbar: { alwaysConsumeMouseWheel: false },
    stickyScroll: { enabled: false },
    wordWrap: 'on' as const,
}));

function onChange(value: string | undefined): void {
    const next = value ?? '';
    const limit = props.field.maxLength;

    // `maxlength` on the textarea was a browser-enforced truncation and the
    // server counts the same characters, so dropping the limit entirely when
    // the control changed would move the first complaint from the keystroke to
    // the submit.
    if (limit !== null && next.length > limit) {
        emit('update:modelValue', next.slice(0, limit));

        return;
    }

    emit('update:modelValue', next);
}
</script>

<template>
    <FieldWrapper
        v-slot="{ controlId, describedBy, invalid: ariaInvalid }"
        :name="field.name"
        :inline-label="field.inlineLabel"
        :label="field.label"
        :required="field.required"
        :helper-text="field.helperText"
        :error="error"
    >
        <div
            ref="container"
            data-slot="code-editor"
            class="overflow-hidden rounded-md border border-input transition-colors focus-within:border-ring"
            :class="
                error
                    ? 'border-destructive focus-within:border-destructive'
                    : ''
            "
        >
            <div
                class="flex items-center justify-between border-b border-input bg-muted/40 px-2 py-1 text-xs text-muted-foreground"
            >
                <span>{{ languageLabel(field.language) }}</span>
                <span>{{ lineCount }} lines</span>
            </div>

            <VueMonacoEditor
                v-if="monacoReady"
                :value="text"
                :language="MONACO_LANGUAGES[field.language]"
                :theme="scheme === 'dark' ? 'vs-dark' : 'vs'"
                :options="options"
                width="100%"
                :height="height"
                @change="onChange"
            />

            <!--
                The control until the editor's chunk arrives, and the control
                for good if it never does. Same value, same name, same
                describing sentences — a field that degrades has to degrade
                into something a keyboard and a screen reader can still use.
            -->
            <Textarea
                v-else
                :id="controlId"
                :aria-describedby="describedBy"
                class="rounded-none border-0 font-mono text-sm shadow-none focus-visible:ring-0"
                spellcheck="false"
                autocapitalize="off"
                autocomplete="off"
                autocorrect="off"
                :model-value="text"
                :rows="field.rows"
                :placeholder="field.placeholder ?? undefined"
                :disabled="field.disabled"
                :maxlength="field.maxLength ?? undefined"
                :aria-invalid="ariaInvalid"
                @update:model-value="
                    (value) => emit('update:modelValue', String(value))
                "
            />
        </div>
    </FieldWrapper>
</template>
