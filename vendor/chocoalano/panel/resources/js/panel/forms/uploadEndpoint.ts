import { inject, provide } from 'vue';
import type { InjectionKey } from 'vue';
import { postForm } from '@/panel/forms/http';

/**
 * Where a file field sends its file.
 *
 * The request names a resource and a field, never a disk or a directory —
 * both of those come from the field's own declaration on the server. That is
 * the whole reason uploading happens in its own request: a form submit that
 * carried files would have to be told where to put them, and being told is
 * exactly what must not happen.
 */
const UPLOAD_URL: InjectionKey<() => string | null> = Symbol(
    'panel.form.uploadUrl',
);

export function provideUploadUrl(url: () => string | null): void {
    provide(UPLOAD_URL, url);
}

/**
 * Null when the form provided none — a form outside a resource has nowhere to
 * put a file, and a file field there says so rather than failing on drop.
 */
export function useUploadUrl(): () => string | null {
    return inject(UPLOAD_URL, () => null);
}

/** What the server stored, as it will be persisted and previewed. */
export interface StoredFile {
    path: string;
    name: string;
}

function toStoredFile(payload: unknown): StoredFile | null {
    if (typeof payload !== 'object' || payload === null) {
        return null;
    }

    const { path, name } = payload as { path?: unknown; name?: unknown };

    if (typeof path !== 'string' || path === '') {
        return null;
    }

    return { path, name: typeof name === 'string' ? name : path };
}

/**
 * Stores one file and answers with the path the form will submit.
 *
 * Null on failure. The field then keeps the files it already had and reports
 * the failure itself, rather than adding an entry pointing at nothing.
 */
export async function uploadFile(
    url: string,
    field: string,
    file: File,
): Promise<StoredFile | null> {
    const body = new FormData();

    body.append('field', field);
    body.append('file', file);

    // Everything that says *what this form is* — the resource, the page, the
    // record, the relation and operation, or the action — is already in the
    // URL the server built, and is read from there and nowhere else. The
    // field name and the file are the only things the client contributes.
    return toStoredFile(await postForm(url, body));
}
