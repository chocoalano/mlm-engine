import type {
    BadgeCell,
    EditableCell,
    BadgeColorName,
    BooleanCell,
    ColorCell,
    DateCell,
    IconCell,
    ImageCell,
    NumberCell,
} from '@/panel/types/table';

/**
 * A column's declared type and its cell value are produced together on the
 * server, but TypeScript cannot prove that across the wire. These guards
 * narrow the value instead of asserting it, so a shape mismatch renders an
 * empty cell rather than throwing inside the table.
 */
function isRecord(value: unknown): value is Record<string, unknown> {
    return typeof value === 'object' && value !== null;
}

const BADGE_COLORS: readonly BadgeColorName[] = [
    'neutral',
    'success',
    'warning',
    'danger',
    'info',
];

export function asTextCell(value: unknown): string | null {
    return typeof value === 'string' && value !== '' ? value : null;
}

export function asNumberCell(value: unknown): NumberCell {
    return isRecord(value) &&
        typeof value.display === 'string' &&
        typeof value.raw === 'number'
        ? { display: value.display, raw: value.raw }
        : null;
}

export function asBadgeCell(value: unknown): BadgeCell {
    if (
        !isRecord(value) ||
        typeof value.value !== 'string' ||
        typeof value.label !== 'string'
    ) {
        return null;
    }

    const color = BADGE_COLORS.find((candidate) => candidate === value.color);

    return {
        value: value.value,
        label: value.label,
        color: color ?? 'neutral',
    };
}

export function asBooleanCell(value: unknown): BooleanCell {
    return isRecord(value) && typeof value.value === 'boolean'
        ? {
              value: value.value,
              label: typeof value.label === 'string' ? value.label : '',
          }
        : { value: false, label: '' };
}

export function asDateCell(value: unknown): DateCell {
    return isRecord(value) &&
        typeof value.display === 'string' &&
        typeof value.iso === 'string'
        ? { display: value.display, iso: value.iso }
        : null;
}

export function asImageCell(value: unknown): ImageCell {
    if (!isRecord(value)) {
        return { url: null, fallback: '', alt: '' };
    }

    return {
        url: typeof value.url === 'string' ? value.url : null,
        fallback: typeof value.fallback === 'string' ? value.fallback : '',
        alt: typeof value.alt === 'string' ? value.alt : '',
    };
}

export function asIconCell(value: unknown): IconCell {
    return isRecord(value) &&
        typeof value.icon === 'string' &&
        typeof value.label === 'string' &&
        BADGE_COLORS.includes(value.color as BadgeColorName)
        ? {
              icon: value.icon,
              color: value.color as BadgeColorName,
              label: value.label,
          }
        : null;
}

export function asColorCell(value: unknown): ColorCell {
    return isRecord(value) &&
        typeof value.color === 'string' &&
        typeof value.label === 'string'
        ? { color: value.color, label: value.label }
        : null;
}

export function asEditableCell(value: unknown): EditableCell | null {
    if (!isRecord(value) || typeof value.disabled !== 'boolean') {
        return null;
    }

    if (typeof value.value !== 'string' && typeof value.value !== 'boolean') {
        return null;
    }

    return {
        value: value.value,
        label: typeof value.label === 'string' ? value.label : undefined,
        disabled: value.disabled,
    };
}
