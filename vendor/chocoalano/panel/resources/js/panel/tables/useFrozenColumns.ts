import {
    getCurrentInstance,
    onBeforeUnmount,
    onMounted,
    ref,
    watch,
} from 'vue';
import type { ComponentPublicInstance, CSSProperties, Ref } from 'vue';

/**
 * Where each frozen column has to sit, measured rather than declared.
 *
 * A `position: sticky` cell needs a `left` (or `right`) offset, and that
 * offset is the total width of every frozen column before it. Nothing on the
 * server knows those widths: a column may declare none at all, and the ones
 * that do can be overridden by content. So the widths are read from the cells
 * the browser actually laid out, and re-read whenever they change.
 *
 * The alternative — requiring `width()` on every frozen column and adding the
 * numbers up in PHP — was rejected because it is wrong exactly when it
 * matters. A column sized to its content is the normal case, and a table
 * whose frozen columns drifted one pixel out of line on a long name would be
 * worse than one that never froze anything.
 *
 * Measuring from layout is what makes the rest of this file careful. Reading
 * the browser and writing reactive state are the two halves of a loop, and a
 * loop that closes is the "Maximum recursive updates exceeded" a table used
 * to throw the moment a column was pinned:
 *
 *   1. a render hands every header cell its template ref,
 *   2. the ref callback measures and writes the widths,
 *   3. the write re-renders, which hands out the refs again.
 *
 * Three things break it, and all three have to hold. The ref callback is
 * *stable per column key*, so Vue does not treat it as a new ref on every
 * render and re-invoke it. The callback is a *no-op when handed the element
 * it already holds*. And a measurement only *writes* when a width actually
 * moved — by more than `WIDTH_EPSILON`, because sub-pixel layout jitter is
 * not a change anybody asked to re-render for.
 */

/** Frozen cells sit above ordinary ones; frozen headers above those. */
const BODY_Z = 10;
const HEADER_Z = 20;

/**
 * The share of the scroll lane the frozen columns on **one side** may take.
 *
 * Evaluated per side, and that is the whole point. The pinning used to be
 * dropped when the two sides *together* passed the threshold, which meant a
 * pinned actions column at the right could unpin the two identity columns at
 * the left — on a phone, where those two columns are the only reason the
 * table is readable at all. The sides do not compete for the same room in any
 * way a user experiences: what makes a table unusable is one edge growing
 * until there is no lane left to scroll, and that is what this measures.
 *
 * Set high enough that two identity columns on a 360px phone stay pinned —
 * a number, a name and the row's actions leave that side well inside it — and
 * low enough that a side which has all but swallowed the lane lets go. Above
 * it, that side behaves like an ordinary column group — the honest
 * degradation, and the reason this is measured on every resize rather than
 * decided once.
 *
 * The old value, 0.6 applied to both sides at once, was wrong twice over: too
 * low for a phone, and asked of a total that no user experiences as one
 * quantity.
 */
const MAX_FROZEN_SHARE = 0.85;

/**
 * How much a width has to move before it counts as having moved.
 *
 * Layout is fractional, a `ResizeObserver` fires on changes far below a
 * pixel, and a re-render per sub-pixel is how a measured layout turns into a
 * render loop. Half a pixel is beneath what any offset can express.
 */
const WIDTH_EPSILON = 0.5;

/** The scrolling element `Table` wraps every table in. */
const LANE_SELECTOR = '[data-slot="table-container"]';

export interface FrozenColumn {
    /** The key a cell identifies itself with — a column name, or a slot id. */
    key: string;
    side: 'start' | 'end';
}

/**
 * What Vue hands a template ref.
 *
 * `TableHead` is a component, so the ref is its instance rather than the
 * element — which is why the element has to be unwrapped from `$el` before
 * anything can be measured.
 */
type RefTarget = Element | ComponentPublicInstance | null;

/**
 * Unwraps whatever a template ref holds into an element.
 *
 * A ref on a component holds the instance, and the element is under `$el` —
 * which is a *comment node* for a component with a fragment root, so the
 * check has to be for an `Element` rather than for anything truthy.
 */
function elementOf(target: RefTarget | undefined): Element | null {
    if (target === null || target === undefined) {
        return null;
    }

    if (target instanceof Element) {
        return target;
    }

    const root = (target as { $el?: unknown }).$el;

    return root instanceof Element ? root : null;
}

export type UseFrozenColumnsReturn = {
    /**
     * Registers the header cell whose width defines this column's.
     *
     * The same callback every time for a given key: a fresh closure per
     * render is a fresh ref as far as Vue is concerned, and a ref that
     * changes identity is re-invoked on every patch.
     */
    measure: (key: string) => (target: RefTarget) => void;
    /** The sticky styles for one cell, or an empty object when it is not frozen. */
    styleFor: (key: string, header?: boolean) => CSSProperties;
    /** Whether this cell sits at the boundary, and should carry the divider. */
    isEdge: (key: string) => boolean;
    /** Which edge a key is pinned to, or null when it is not pinned at all. */
    sideOf: (key: string) => 'start' | 'end' | null;
    /** False while the leading columns would take too much of the scroll lane. */
    activeStart: Ref<boolean>;
    /** The same question for the trailing columns, asked separately. */
    activeEnd: Ref<boolean>;
    /** Measures now rather than on the next frame. */
    refresh: () => void;
    /** Cancels the pending frame, drops the observer, forgets every element. */
    stop: () => void;
};

/** Whether two measurements say the same thing, to within a half pixel. */
function sameWidths(
    current: Record<string, number>,
    next: Record<string, number>,
): boolean {
    const keys = Object.keys(current);

    if (keys.length !== Object.keys(next).length) {
        return false;
    }

    return keys.every((key) => {
        const measured = next[key];

        return (
            measured !== undefined &&
            Math.abs(current[key] - measured) < WIDTH_EPSILON
        );
    });
}

/**
 * A frame scheduler that also works where there is no compositor.
 *
 * Measurement belongs in a frame: it reads layout, and reading layout in the
 * middle of the patch that produced it is what forces a synchronous reflow
 * per cell. `requestAnimationFrame` is missing in a unit-test environment,
 * where the fallback is only asked to be asynchronous.
 */
const nextFrame: (callback: () => void) => number =
    typeof requestAnimationFrame === 'function'
        ? (callback) => requestAnimationFrame(callback)
        : (callback) => setTimeout(callback, 0) as unknown as number;

const cancelFrame: (handle: number) => void =
    typeof cancelAnimationFrame === 'function'
        ? (handle) => cancelAnimationFrame(handle)
        : (handle) => clearTimeout(handle);

export function useFrozenColumns(
    columns: Ref<FrozenColumn[]>,
    /**
     * The table, however the caller happens to hold it: the wrapper element,
     * the `Table` component's instance, or nothing yet. Anything but an
     * element is unwrapped — a caller that pointed this at a component ref
     * and got silence would have no way of telling that from a table which
     * simply fits.
     */
    container: Ref<HTMLElement | ComponentPublicInstance | null>,
): UseFrozenColumnsReturn {
    const widths = ref<Record<string, number>>({});

    // One answer per side. A single flag is the mobile bug: it lets the
    // actions column at the right decide the fate of the name column at the
    // left.
    const activeStart = ref(true);
    const activeEnd = ref(true);

    const elements = new Map<string, Element>();
    const refCallbacks = new Map<string, (target: RefTarget) => void>();

    let observer: ResizeObserver | null = null;
    let lane: Element | null = null;
    let frame: number | null = null;

    /**
     * The element the frozen columns are actually pinned inside.
     *
     * `Table` renders a scrolling wrapper around the `<table>`, and that
     * wrapper is the lane: it is the box whose width the pinning has to fit
     * inside and the box whose `scrollLeft` the sticky offsets are relative
     * to. Measuring the outer container instead compares the frozen columns
     * to a width that is not the one being scrolled — which reads correct
     * when they happen to match and is wrong the moment a table sits in a
     * padded or bordered shell.
     */
    function scrollLane(): Element | null {
        const root = elementOf(container.value);

        if (root === null) {
            return null;
        }

        if (typeof root.matches === 'function' && root.matches(LANE_SELECTOR)) {
            return root;
        }

        return root.querySelector?.(LANE_SELECTOR) ?? root;
    }

    function laneWidth(): number {
        return scrollLane()?.clientWidth ?? 0;
    }

    function sideWidth(
        measured: Record<string, number>,
        side: 'start' | 'end',
    ): number {
        return columns.value
            .filter((column) => column.side === side)
            .reduce((total, column) => total + (measured[column.key] ?? 0), 0);
    }

    /** Nothing frozen is always fine; the guard is only about how much. */
    function fits(width: number, available: number): boolean {
        return (
            width === 0 ||
            available === 0 ||
            width / available <= MAX_FROZEN_SHARE
        );
    }

    function remeasure(): void {
        const next: Record<string, number> = {};

        for (const [key, element] of elements) {
            next[key] = element.getBoundingClientRect().width;
        }

        // The one write worth guarding. Assigning an equal-but-new object is
        // still a reactive write, and a reactive write from inside a ref
        // callback is a render that schedules the render that ran it.
        if (!sameWidths(widths.value, next)) {
            widths.value = next;
        }

        const available = laneWidth();

        const nextStart = fits(sideWidth(next, 'start'), available);
        const nextEnd = fits(sideWidth(next, 'end'), available);

        if (activeStart.value !== nextStart) {
            activeStart.value = nextStart;
        }

        if (activeEnd.value !== nextEnd) {
            activeEnd.value = nextEnd;
        }
    }

    function schedule(): void {
        if (frame !== null) {
            return;
        }

        frame = nextFrame(() => {
            frame = null;
            remeasure();
        });
    }

    function measure(key: string): (target: RefTarget) => void {
        const existing = refCallbacks.get(key);

        if (existing !== undefined) {
            return existing;
        }

        const callback = (target: RefTarget): void => {
            const element = elementOf(target);
            const current = elements.get(key) ?? null;

            // Vue re-sending the cell it sent last time is the common case,
            // and doing anything about it — re-observing, re-measuring,
            // re-writing — is the loop.
            if (current === element) {
                return;
            }

            if (current !== null) {
                observer?.unobserve(current);
                elements.delete(key);
            }

            if (element !== null) {
                elements.set(key, element);
                observer?.observe(element);
            }

            schedule();
        };

        refCallbacks.set(key, callback);

        return callback;
    }

    function start(): void {
        if (observer !== null) {
            return;
        }

        observer = new ResizeObserver(schedule);

        for (const element of elements.values()) {
            observer.observe(element);
        }

        lane = scrollLane();

        if (lane !== null) {
            observer.observe(lane);
        }

        remeasure();
    }

    function stop(): void {
        if (frame !== null) {
            cancelFrame(frame);
            frame = null;
        }

        observer?.disconnect();
        observer = null;
        lane = null;

        elements.clear();
        refCallbacks.clear();
    }

    // Outside a component — a test, or a composable assembled by hand — there
    // is no mount to wait for and no unmount to clean up on.
    if (getCurrentInstance() !== null) {
        onMounted(start);
        onBeforeUnmount(stop);
    } else {
        start();
    }

    // A column hidden from the column manager, or a schema swapped by a
    // filter, changes which cells exist and therefore every offset after them.
    watch(columns, schedule);

    // A table re-rendered into a different wrapper is a different lane, and a
    // lane nobody observes is a threshold that never re-evaluates.
    watch(container, () => {
        if (observer === null) {
            return;
        }

        if (lane !== null) {
            observer.unobserve(lane);
        }

        lane = scrollLane();

        if (lane !== null) {
            observer.observe(lane);
        }

        schedule();
    });

    function sideOf(key: string): 'start' | 'end' | null {
        return columns.value.find((column) => column.key === key)?.side ?? null;
    }

    function isSideActive(side: 'start' | 'end'): boolean {
        return side === 'start' ? activeStart.value : activeEnd.value;
    }

    function offsetFor(
        key: string,
    ): { side: 'start' | 'end'; offset: number } | null {
        const index = columns.value.findIndex((column) => column.key === key);

        if (index === -1) {
            return null;
        }

        const column = columns.value[index];

        // Leading columns accumulate from the left in order; trailing ones
        // accumulate from the right, so they are summed in reverse.
        const preceding =
            column.side === 'start'
                ? columns.value.slice(0, index)
                : columns.value.slice(index + 1);

        const offset = preceding
            .filter((other) => other.side === column.side)
            .reduce(
                (total, other) => total + (widths.value[other.key] ?? 0),
                0,
            );

        return { side: column.side, offset };
    }

    function styleFor(key: string, header = false): CSSProperties {
        const resolved = offsetFor(key);

        if (resolved === null) {
            return {};
        }

        // Only this column's own side has a say. A wide actions column at the
        // right unpinning the name column at the left is the bug this
        // replaces.
        if (!isSideActive(resolved.side)) {
            return {};
        }

        return {
            position: 'sticky',
            [resolved.side === 'start' ? 'left' : 'right']:
                `${resolved.offset}px`,
            zIndex: header ? HEADER_Z : BODY_Z,
        };
    }

    function isEdge(key: string): boolean {
        const side = sideOf(key);

        if (side === null || !isSideActive(side)) {
            return false;
        }

        const onSide = columns.value.filter((column) => column.side === side);

        return side === 'start'
            ? onSide[onSide.length - 1]?.key === key
            : onSide[0]?.key === key;
    }

    return {
        measure,
        styleFor,
        isEdge,
        sideOf,
        activeStart,
        activeEnd,
        refresh: remeasure,
        stop,
    };
}
