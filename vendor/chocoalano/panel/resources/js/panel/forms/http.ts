/**
 * The two things every side request a form makes has in common: a CSRF token
 * and the headers that mark it as XHR rather than a navigation.
 *
 * These are side requests by design. A form's own submit goes through Inertia
 * and gets a page back; asking what the schema looks like now, or storing one
 * file, must not — a full page response would discard what the user is in the
 * middle of typing.
 */

export function csrfToken(): string {
    return (
        document
            .querySelector('meta[name="csrf-token"]')
            ?.getAttribute('content') ??
        decodeURIComponent(
            document.cookie
                .split('; ')
                .find((row) => row.startsWith('XSRF-TOKEN='))
                ?.split('=')[1] ?? '',
        )
    );
}

/**
 * Posts JSON and returns the parsed body, or null for anything that did not
 * work.
 *
 * Null rather than a throw: every caller here is enriching a form that is
 * already usable, so a failed side request must leave it as it was rather
 * than break the page the user is filling in.
 */
export async function postJson(
    url: string,
    body: unknown,
    signal?: AbortSignal,
): Promise<unknown | null> {
    try {
        const response = await fetch(url, {
            method: 'POST',
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-CSRF-TOKEN': csrfToken(),
            },
            credentials: 'same-origin',
            body: JSON.stringify(body),
            signal,
        });

        return response.ok ? await response.json() : null;
    } catch {
        return null;
    }
}

/**
 * Posts a `FormData` body, for the one request that carries a file.
 */
export async function postForm(
    url: string,
    body: FormData,
): Promise<unknown | null> {
    try {
        const response = await fetch(url, {
            method: 'POST',
            headers: {
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-CSRF-TOKEN': csrfToken(),
            },
            credentials: 'same-origin',
            body,
        });

        return response.ok ? await response.json() : null;
    } catch {
        return null;
    }
}
