import { inject, provide } from 'vue';
import type { InjectionKey } from 'vue';
import { postJson } from '@/panel/forms/http';
import type { FormDefinition, FormValues } from '@/panel/types/form';

/**
 * Where a `live()` field asks what the form should look like now.
 *
 * Provided rather than passed down, for the reason the options URL is: a
 * field can sit four layouts deep and every one of them would otherwise carry
 * a prop it does not use.
 *
 * The URL already names the resource, the page, and — when editing — the
 * record. The client sends only the values and which field changed, so a
 * keystroke can never change which form is being asked about.
 */
const FORM_STATE_URL: InjectionKey<() => string | null> = Symbol(
    'panel.form.formStateUrl',
);

export function provideFormStateUrl(url: () => string | null): void {
    provide(FORM_STATE_URL, url);
}

/**
 * Null when the form provided none, which is the honest answer for a form
 * rendered outside a resource: its live fields then behave as ordinary ones.
 */
export function useFormStateUrl(): () => string | null {
    return inject(FORM_STATE_URL, () => null);
}

/**
 * What the server answers a rebuild with.
 *
 * Two things, and they are not the same thing. `form` is what the form should
 * look like now; `statePatch` is what the server has decided it should now
 * hold, and is only ever non-empty when a callback explicitly changed a field.
 *
 * The split exists because this component preserves what the user typed
 * across a rebuild — it has to, or every rebuild would discard the rest of the
 * form. That left a server which wanted to clear a now-invalid child field
 * with no way to say so: it could remove the value from the schema and watch
 * the client put it straight back. A patch is the server saying it, and it is
 * the one thing that wins over the client's own state.
 */
export type FormStateResponse = {
    form: FormDefinition;
    /** Paths the server changed, by the name the schema gave them. */
    statePatch: Record<string, unknown>;
};

/**
 * Narrowed rather than asserted. This crosses the wire as untyped JSON like
 * every other payload, and a shape that does not match must leave the form
 * showing what it already had.
 */
function toFormStateResponse(payload: unknown): FormStateResponse | null {
    if (typeof payload !== 'object' || payload === null) {
        return null;
    }

    const form = (payload as { form?: unknown }).form;

    if (typeof form !== 'object' || form === null) {
        return null;
    }

    const schema = (form as { schema?: unknown }).schema;
    const columns = (form as { columns?: unknown }).columns;

    if (!Array.isArray(schema) || typeof columns !== 'number') {
        return null;
    }

    const patch = (payload as { statePatch?: unknown }).statePatch;

    return {
        form: form as FormDefinition,
        // Absent on a server that predates patches, and on every response that
        // had nothing to patch. Both mean the same thing here.
        statePatch:
            typeof patch === 'object' && patch !== null && !Array.isArray(patch)
                ? (patch as Record<string, unknown>)
                : {},
    };
}

/**
 * Asks the server to rebuild the schema against what has been typed.
 *
 * Returns null on any failure, so a request that could not be answered leaves
 * the form exactly as it was. Rebuilding is an enrichment — a select whose
 * options depend on another field, a total computed from three of them — and
 * losing it must not cost the user what they have entered.
 */
export async function fetchFormState(
    url: string,
    values: FormValues,
    changed: string,
    previous: unknown,
    signal?: AbortSignal,
): Promise<FormStateResponse | null> {
    const payload = await postJson(
        url,
        { state: values, changed, previous },
        signal,
    );

    return payload === null ? null : toFormStateResponse(payload);
}
