import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { describe, expect, it } from 'vitest';

/**
 * Contrast of the token pairs the UI actually draws together.
 *
 * Read out of the stylesheet rather than restated here, so a token edited to a
 * prettier value fails this rather than quietly dropping below the threshold.
 * The maths is WCAG 2.x relative luminance and contrast ratio — kept on the
 * test side because nothing at runtime needs to compute it.
 *
 *   L  = 0.2126 R + 0.7152 G + 0.0722 B  over linearised channels
 *   CR = (Lmax + 0.05) / (Lmin + 0.05)
 *
 * These are opaque-pair calculations. A token drawn at reduced opacity
 * composites against whatever is behind it and is *not* covered here — see the
 * wave report for what remains unmeasured.
 */
const CSS = readFileSync(
    fileURLToPath(new URL('../../css/panda-panel.css', import.meta.url)),
    'utf8',
);

/** The `:root` block is light; the `.dark` block is dark. */
function block(selector: ':root' | '.dark'): string {
    const start = CSS.indexOf(`${selector} {`);
    const end = CSS.indexOf('\n}', start);

    return CSS.slice(start, end);
}

function token(scheme: ':root' | '.dark', name: string): string {
    const match = block(scheme).match(
        new RegExp(`--${name}:\\s*(hsl\\([^)]*\\)|#[0-9a-fA-F]{3,8})`),
    );

    if (match === null) {
        throw new Error(`--${name} is not defined in ${scheme}`);
    }

    return match[1];
}

function toRgb(value: string): [number, number, number] {
    if (value.startsWith('#')) {
        const hex = value.slice(1);

        return [0, 2, 4].map((i) => parseInt(hex.slice(i, i + 2), 16)) as [
            number,
            number,
            number,
        ];
    }

    const [h, s, l] = (value.match(/-?[\d.]+/g) ?? []).map(Number);
    const sat = s / 100;
    const lum = l / 100;
    const a = sat * Math.min(lum, 1 - lum);
    const f = (n: number): number => {
        const k = (n + h / 30) % 12;

        return lum - a * Math.max(-1, Math.min(k - 3, Math.min(9 - k, 1)));
    };

    return [f(0), f(8), f(4)].map((v) => Math.round(v * 255)) as [
        number,
        number,
        number,
    ];
}

function luminance(rgb: [number, number, number]): number {
    const [r, g, b] = rgb.map((channel) => {
        const c = channel / 255;

        return c <= 0.03928 ? c / 12.92 : ((c + 0.055) / 1.055) ** 2.4;
    });

    return 0.2126 * r + 0.7152 * g + 0.0722 * b;
}

function ratio(a: string, b: string): number {
    const x = luminance(toRgb(a));
    const y = luminance(toRgb(b));

    return (Math.max(x, y) + 0.05) / (Math.min(x, y) + 0.05);
}

function contrast(scheme: ':root' | '.dark', a: string, b: string): number {
    return ratio(token(scheme, a), token(scheme, b));
}

/*
 * T05 — a filled destructive button's label
 */

describe('destructive', () => {
    it('carries readable text in light', () => {
        // Was 3.61:1 with white on hsl(0 84.2% 60.2%).
        expect(
            contrast(':root', 'destructive', 'destructive-foreground'),
        ).toBeGreaterThanOrEqual(4.5);
    });

    it('carries readable text in dark', () => {
        expect(
            contrast('.dark', 'destructive', 'destructive-foreground'),
        ).toBeGreaterThanOrEqual(4.5);
    });
});

/*
 * T06 — muted text on the surface it is drawn on
 */

describe('muted', () => {
    it('is readable on the muted surface in light', () => {
        // A neutral badge and a tab count sit on --muted, not on --background.
        // Was 4.35:1 there while passing on white, which is why it survived.
        expect(
            contrast(':root', 'muted-foreground', 'muted'),
        ).toBeGreaterThanOrEqual(4.5);
    });

    it('is readable on the background in light', () => {
        expect(
            contrast(':root', 'muted-foreground', 'background'),
        ).toBeGreaterThanOrEqual(4.5);
    });

    it('is readable on the muted surface in dark', () => {
        expect(
            contrast('.dark', 'muted-foreground', 'muted'),
        ).toBeGreaterThanOrEqual(4.5);
    });
});

/*
 * T02 — a branding mark drawn with currentColor
 */

describe('sidebar primary', () => {
    it('distinguishes the mark from its surface in dark', () => {
        // Both were white: 1:1, so the mark vanished.
        expect(
            contrast('.dark', 'sidebar-primary', 'sidebar-primary-foreground'),
        ).toBeGreaterThanOrEqual(4.5);
    });

    it('distinguishes them in light too', () => {
        expect(
            contrast(':root', 'sidebar-primary', 'sidebar-primary-foreground'),
        ).toBeGreaterThanOrEqual(4.5);
    });
});

/*
 * T09 — the boundary that says "you can type here"
 */

describe('input boundary', () => {
    it('meets the non-text threshold in light', () => {
        // WCAG 1.4.11: 3:1 for the visual boundary of a control. Was 1.26:1.
        expect(contrast(':root', 'input', 'background')).toBeGreaterThanOrEqual(
            3,
        );
    });

    it('meets it in dark', () => {
        expect(contrast('.dark', 'input', 'background')).toBeGreaterThanOrEqual(
            3,
        );
    });

    it('leaves the decorative border alone', () => {
        // Deliberately not held to 3:1: separators and card edges are not
        // controls, and a UI of 3:1 lines is a worse UI.
        expect(contrast(':root', 'border', 'background')).toBeLessThan(3);
    });
});

/*
 * T08 — the status vocabulary
 */

describe('status colours', () => {
    it('reads as text on the background in light', () => {
        expect(
            contrast(':root', 'success', 'background'),
        ).toBeGreaterThanOrEqual(4.5);
        expect(
            contrast(':root', 'warning', 'background'),
        ).toBeGreaterThanOrEqual(4.5);
    });

    it('reads as text on the background in dark', () => {
        expect(
            contrast('.dark', 'success', 'background'),
        ).toBeGreaterThanOrEqual(4.5);
        expect(
            contrast('.dark', 'warning', 'background'),
        ).toBeGreaterThanOrEqual(4.5);
    });

    it('carries readable labels when filled', () => {
        expect(
            contrast(':root', 'success', 'success-foreground'),
        ).toBeGreaterThanOrEqual(4.5);
        expect(
            contrast('.dark', 'success', 'success-foreground'),
        ).toBeGreaterThanOrEqual(4.5);
    });
});

/*
 * T08 — the same tokens drawn as text rather than as a fill
 *
 * An error message and a "saved." line are the token itself on the page
 * background, not a filled control, so the pair that matters is a different
 * one from the button pairs above. This is what the literal `text-red-600`
 * and `text-emerald-600` were replaced with, and emerald-600 on white was
 * 3.77:1 — the replacement has to be better than what it replaced, not merely
 * more consistent.
 */

describe('semantic colours used as text', () => {
    it('reads on the background in light', () => {
        expect(
            contrast(':root', 'destructive', 'background'),
        ).toBeGreaterThanOrEqual(4.5);
        expect(
            contrast(':root', 'success', 'background'),
        ).toBeGreaterThanOrEqual(4.5);
    });

    it('reads on the background in dark', () => {
        expect(
            contrast('.dark', 'destructive', 'background'),
        ).toBeGreaterThanOrEqual(4.5);
        expect(
            contrast('.dark', 'success', 'background'),
        ).toBeGreaterThanOrEqual(4.5);
    });
});

/*
 * T09 — native controls follow the resolved appearance
 */

describe('color-scheme', () => {
    it('is declared for both schemes', () => {
        // Without it the browser draws date pickers, file inputs and
        // scrollbars light on a dark panel.
        expect(block(':root')).toContain('color-scheme: light');
        expect(block('.dark')).toContain('color-scheme: dark');
    });
});
