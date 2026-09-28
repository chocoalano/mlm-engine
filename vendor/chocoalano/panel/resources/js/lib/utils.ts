import type { InertiaLinkProps } from '@inertiajs/vue3';
import { clsx } from 'clsx';
import type { ClassValue } from 'clsx';
import { twMerge } from 'tailwind-merge';

export function cn(...inputs: ClassValue[]) {
    return twMerge(clsx(inputs));
}

export function toUrl(href: NonNullable<InertiaLinkProps['href']>) {
    return typeof href === 'string' ? href : href?.url;
}

export function safeUrl(value: string | null | undefined): string | null {
    const trimmed = value?.trim();

    if (!trimmed) {
        return null;
    }

    // Control characters are the point: a NUL or a tab inside `javascript:`
    // still parses as `javascript:` in a browser, so they have to come out
    // before the scheme is read. `no-control-regex` exists to catch a control
    // character somebody typed by accident, which is the opposite of this.
    // eslint-disable-next-line no-control-regex
    const compact = trimmed.replace(/[\u0000-\u0020\u007f]+/g, '');

    if (
        compact === '' ||
        compact.startsWith('//') ||
        compact.startsWith('\\')
    ) {
        return null;
    }

    const scheme = compact.match(/^([a-z][a-z0-9+.-]*):/i)?.[1]?.toLowerCase();

    if (!scheme) {
        return trimmed;
    }

    return ['http', 'https', 'mailto', 'tel'].includes(scheme) ? trimmed : null;
}
