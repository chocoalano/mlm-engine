import { ref, watchSyncEffect } from 'vue';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { useFrozenColumns } from '@/panel/tables/useFrozenColumns';
import type { FrozenColumn } from '@/panel/tables/useFrozenColumns';

/*
 * The half of freezing that is not the browser's.
 *
 * These cover the two failures that made pinned columns unusable and are
 * invisible to a type checker: the measurement loop that ended in "Maximum
 * recursive updates exceeded", and the narrow-screen guard that let a pinned
 * actions column at one edge unpin the identity columns at the other.
 *
 * The DOM is faked rather than emulated. Everything asserted here is
 * arithmetic over widths — which cell is observed, which write happens, which
 * side is dropped — and a real layout engine would decide none of it. What a
 * real browser decides is covered by `tests/browser/frozen-columns.mjs`.
 */

class FakeElement {
    public width = 0;
    public clientWidth = 0;

    private readonly slot: string | null;
    private readonly children: FakeElement[];

    public constructor(
        width = 0,
        slot: string | null = null,
        children: FakeElement[] = [],
    ) {
        this.width = width;
        this.clientWidth = width;
        this.slot = slot;
        this.children = children;
    }

    public getBoundingClientRect(): { width: number } {
        return { width: this.width };
    }

    public matches(selector: string): boolean {
        return this.slot !== null && selector.includes(this.slot);
    }

    public querySelector(selector: string): FakeElement | null {
        return (
            this.children.find((child) => child.matches(selector)) ??
            this.children
                .map((child) => child.querySelector(selector))
                .find((found) => found !== null) ??
            null
        );
    }
}

class FakeResizeObserver {
    public static instances: FakeResizeObserver[] = [];

    public readonly observed: FakeElement[] = [];
    public disconnected = false;

    private readonly callback: () => void;

    public constructor(callback: () => void) {
        this.callback = callback;
        FakeResizeObserver.instances.push(this);
    }

    public observe(element: FakeElement): void {
        this.observed.push(element);
    }

    public unobserve(element: FakeElement): void {
        const index = this.observed.indexOf(element);

        if (index !== -1) {
            this.observed.splice(index, 1);
        }
    }

    public disconnect(): void {
        this.disconnected = true;
        this.observed.length = 0;
    }

    public fire(): void {
        this.callback();
    }
}

/**
 * Every composable a test started, so the fakes outlive nothing.
 *
 * A measurement is scheduled on a frame, and a frame that runs after the
 * globals have been unstubbed measures against an `Element` that no longer
 * exists. Which is the same reason `stop()` cancels the pending frame in a
 * component: teardown has to reach the work that has not happened yet.
 */
const started: Array<{ stop: () => void }> = [];

function track<T extends { stop: () => void }>(frozen: T): T {
    started.push(frozen);

    return frozen;
}

beforeEach(() => {
    FakeResizeObserver.instances = [];
    vi.stubGlobal('Element', FakeElement);
    vi.stubGlobal('ResizeObserver', FakeResizeObserver);
});

afterEach(() => {
    for (const frozen of started.splice(0)) {
        frozen.stop();
    }

    vi.unstubAllGlobals();
});

/** A container whose scroll lane is `clientWidth` wide, as `Table` renders it. */
function laneOf(width: number, cells: FakeElement[] = []): FakeElement {
    return new FakeElement(0, null, [
        new FakeElement(width, 'data-slot="table-container"', cells),
    ]);
}

type Harness = ReturnType<typeof useFrozenColumns>;

/**
 * A mounted table: the columns, the lane, and every header cell already
 * handed to its ref callback and measured.
 */
function mount(
    columns: FrozenColumn[],
    widths: Record<string, number>,
    lane: number,
): Harness & { cells: Record<string, FakeElement> } {
    const cells: Record<string, FakeElement> = {};

    for (const [key, width] of Object.entries(widths)) {
        cells[key] = new FakeElement(width);
    }

    const container = ref(laneOf(lane)) as unknown as ReturnType<
        typeof ref<HTMLElement | null>
    >;

    const frozen = track(
        useFrozenColumns(ref(columns), container as never),
    ) as Harness;

    for (const [key, cell] of Object.entries(cells)) {
        frozen.measure(key)(cell as unknown as Element);
    }

    frozen.refresh();

    return { ...frozen, cells };
}

describe('the measurement loop', () => {
    it('hands out the same ref callback for a key on every render', () => {
        // A fresh closure per render is a fresh ref as far as Vue is
        // concerned, and Vue re-invokes a ref whose identity changed — on
        // every patch, including the patch the last invocation caused. That
        // is the whole of "Maximum recursive updates exceeded".
        const frozen = mount([{ key: 'name', side: 'start' }], {}, 800);

        expect(frozen.measure('name')).toBe(frozen.measure('name'));
        expect(frozen.measure('name')).not.toBe(frozen.measure('email'));
    });

    it('does nothing when Vue hands back the element it already holds', () => {
        const frozen = mount(
            [{ key: 'name', side: 'start' }],
            { name: 150 },
            800,
        );
        const observer = FakeResizeObserver.instances[0];
        const cell = frozen.cells.name;

        const observed = observer.observed.length;

        frozen.measure('name')(cell as unknown as Element);
        frozen.measure('name')(cell as unknown as Element);

        expect(observer.observed.length).toBe(observed);
    });

    it('does not write state when nothing measured differently', () => {
        const frozen = mount(
            [
                { key: 'number', side: 'start' },
                { key: 'name', side: 'start' },
            ],
            { number: 44, name: 150 },
            800,
        );

        let renders = 0;

        watchSyncEffect(() => {
            frozen.styleFor('name');
            renders += 1;
        });

        expect(renders).toBe(1);

        // The observer firing on a layout that did not move is the normal
        // case, not the exception: it fires for a scroll, a font swap, a
        // parent resize that changed nothing here.
        FakeResizeObserver.instances[0].fire();
        frozen.refresh();
        frozen.refresh();

        expect(renders).toBe(1);

        // Sub-pixel jitter is not a change anybody asked to re-render for.
        frozen.cells.number.width = 44.3;
        frozen.refresh();

        expect(renders).toBe(1);

        // A real change still lands.
        frozen.cells.number.width = 64;
        frozen.refresh();

        expect(renders).toBe(2);
        expect(frozen.styleFor('name')).toMatchObject({ left: '64px' });
    });

    it('schedules a measurement rather than taking it inside the ref', async () => {
        vi.resetModules();

        const frames: Array<() => void> = [];
        const cancelled: number[] = [];

        vi.stubGlobal('requestAnimationFrame', (callback: () => void) => {
            frames.push(callback);

            return frames.length;
        });
        vi.stubGlobal('cancelAnimationFrame', (handle: number) => {
            cancelled.push(handle);
        });

        const module = await import('@/panel/tables/useFrozenColumns');

        const container = ref(laneOf(800));
        const frozen = track(
            module.useFrozenColumns(
                ref([
                    { key: 'number', side: 'start' },
                    { key: 'name', side: 'start' },
                ]),
                container as never,
            ),
        );

        frozen.measure('number')(new FakeElement(44) as unknown as Element);
        frozen.measure('name')(new FakeElement(150) as unknown as Element);

        // Reading layout inside the ref callback is a synchronous reflow per
        // cell, in the middle of the patch that produced the layout.
        expect(frames.length).toBe(1);
        expect(frozen.styleFor('name')).toMatchObject({ left: '0px' });

        frames[0]();

        expect(frozen.styleFor('name')).toMatchObject({ left: '44px' });

        // And a teardown mid-flight leaves no frame to run against a torn
        // down table.
        frozen.measure('number')(new FakeElement(80) as unknown as Element);
        frozen.stop();

        expect(cancelled).toHaveLength(1);
    });

    it('measures the scroll lane rather than the shell around it', () => {
        const frozen = mount(
            [{ key: 'name', side: 'start' }],
            { name: 330 },
            360,
        );
        const observer = FakeResizeObserver.instances[0];

        // 330 of a 360 lane is past the threshold; had the outer container
        // been measured, its `clientWidth` of 0 would have read as "no lane
        // to run out of" and kept the pinning.
        expect(frozen.activeStart.value).toBe(false);
        expect(
            observer.observed.some((element) =>
                element.matches('data-slot="table-container"'),
            ),
        ).toBe(true);
    });

    it('forgets everything it held on teardown', () => {
        const frozen = mount(
            [{ key: 'name', side: 'start' }],
            { name: 150 },
            800,
        );
        const observer = FakeResizeObserver.instances[0];

        const before = frozen.measure('name');

        frozen.stop();

        expect(observer.disconnected).toBe(true);
        expect(frozen.measure('name')).not.toBe(before);
    });
});

describe('the container', () => {
    it('unwraps a component ref to the element underneath it', () => {
        // A ref on `<Table>` holds the component instance, not the element.
        // Reading `clientWidth` off the instance yields undefined, which
        // reads as "no lane to run out of" — a threshold that can never
        // trigger, on any screen.
        const lane = laneOf(360);
        const container = ref({ $el: lane } as unknown as HTMLElement);

        const frozen = track(
            useFrozenColumns(
                ref([{ key: 'name', side: 'start' }]),
                container as never,
            ),
        );

        frozen.measure('name')(new FakeElement(330) as unknown as Element);
        frozen.refresh();

        expect(frozen.activeStart.value).toBe(false);
    });

    it('tolerates a container that is not mounted yet', () => {
        const frozen = track(
            useFrozenColumns(ref([{ key: 'name', side: 'start' }]), ref(null)),
        );

        frozen.measure('name')(new FakeElement(330) as unknown as Element);
        frozen.refresh();

        // No lane means no share to be past.
        expect(frozen.activeStart.value).toBe(true);
        expect(frozen.styleFor('name')).toMatchObject({ position: 'sticky' });
    });
});

describe('offsets', () => {
    it('stacks leading columns from the left and trailing ones from the right', () => {
        const frozen = mount(
            [
                { key: 'select', side: 'start' },
                { key: 'number', side: 'start' },
                { key: 'name', side: 'start' },
                { key: 'actions', side: 'end' },
            ],
            { select: 40, number: 44, name: 150, actions: 96 },
            1200,
        );

        expect(frozen.styleFor('select')).toMatchObject({
            position: 'sticky',
            left: '0px',
            zIndex: 10,
        });
        expect(frozen.styleFor('number')).toMatchObject({ left: '40px' });
        expect(frozen.styleFor('name', true)).toMatchObject({
            left: '84px',
            zIndex: 20,
        });
        expect(frozen.styleFor('actions')).toMatchObject({ right: '0px' });
        expect(frozen.styleFor('city')).toEqual({});
    });

    it('marks the last leading column and the first trailing one', () => {
        const frozen = mount(
            [
                { key: 'number', side: 'start' },
                { key: 'name', side: 'start' },
                { key: 'balance', side: 'end' },
                { key: 'actions', side: 'end' },
            ],
            { number: 44, name: 150, balance: 90, actions: 96 },
            1200,
        );

        expect(frozen.isEdge('number')).toBe(false);
        expect(frozen.isEdge('name')).toBe(true);
        expect(frozen.isEdge('balance')).toBe(true);
        expect(frozen.isEdge('actions')).toBe(false);
        expect(frozen.sideOf('name')).toBe('start');
        expect(frozen.sideOf('balance')).toBe('end');
        expect(frozen.sideOf('city')).toBe(null);
    });
});

describe('the narrow-screen guard', () => {
    it('keeps two identity columns pinned on a phone', () => {
        // 360px is the narrowest viewport worth designing for, and two
        // columns that identify a row are the reason the table is legible at
        // all once it scrolls.
        const frozen = mount(
            [
                { key: 'number', side: 'start' },
                { key: 'name', side: 'start' },
                { key: 'actions', side: 'end' },
            ],
            { number: 44, name: 150, actions: 96 },
            360,
        );

        expect(frozen.activeStart.value).toBe(true);
        expect(frozen.styleFor('number')).toMatchObject({ left: '0px' });
        expect(frozen.styleFor('name')).toMatchObject({ left: '44px' });

        // 194 of a 360 lane is 54%, which the old 0.6 threshold would have
        // allowed on its own — and then dropped anyway, because it added the
        // 96px actions column at the other edge to it first.
        expect(frozen.activeEnd.value).toBe(true);
    });

    it('does not let a pinned actions column unpin the identity columns', () => {
        // The two sides summed to 524 of a 360 lane — over any single
        // threshold — and the old guard dropped every pin in the table
        // because of the column at the other edge.
        const frozen = mount(
            [
                { key: 'number', side: 'start' },
                { key: 'name', side: 'start' },
                { key: 'actions', side: 'end' },
            ],
            { number: 44, name: 150, actions: 330 },
            360,
        );

        expect(frozen.activeEnd.value).toBe(false);
        expect(frozen.activeStart.value).toBe(true);
        expect(frozen.styleFor('name')).toMatchObject({ position: 'sticky' });
        expect(frozen.styleFor('actions')).toEqual({});
        expect(frozen.isEdge('actions')).toBe(false);
        expect(frozen.isEdge('name')).toBe(true);
    });

    it('drops a side that has all but swallowed the lane', () => {
        const frozen = mount(
            [
                { key: 'number', side: 'start' },
                { key: 'name', side: 'start' },
                { key: 'actions', side: 'end' },
            ],
            { number: 60, name: 270, actions: 60 },
            360,
        );

        expect(frozen.activeStart.value).toBe(false);
        expect(frozen.styleFor('name')).toEqual({});
        expect(frozen.isEdge('name')).toBe(false);

        // The other edge is unaffected, and still carries its seam.
        expect(frozen.activeEnd.value).toBe(true);
        expect(frozen.styleFor('actions')).toMatchObject({ right: '0px' });
        expect(frozen.isEdge('actions')).toBe(true);
    });

    it('restores the pinning when the lane grows again', () => {
        const frozen = mount(
            [{ key: 'name', side: 'start' }],
            { name: 330 },
            360,
        );

        expect(frozen.activeStart.value).toBe(false);

        frozen.cells.name.width = 150;
        frozen.refresh();

        expect(frozen.activeStart.value).toBe(true);
    });

    it('never drops a table that freezes nothing', () => {
        const frozen = mount([], {}, 0);

        expect(frozen.activeStart.value).toBe(true);
        expect(frozen.activeEnd.value).toBe(true);
    });
});
