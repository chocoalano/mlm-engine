/**
 * @vitest-environment happy-dom
 */
import { flushPromises, mount } from '@vue/test-utils';
import { afterEach, describe, expect, it, vi } from 'vitest';
import type {
    FieldDefinition,
    FormComponentDefinition,
} from '@/panel/types/form';

/**
 * Which entry a repeater's local UI state belongs to.
 *
 * Wave UI-2 separated two identities that had been one string: what a value is
 * *submitted* as, and what the DOM *calls* it. This is the third one it did
 * not finish separating — what a piece of view state is *about*.
 *
 * `collapsed` was a `Set<number>` of array positions. A position is not an
 * identity: move a collapsed entry down and the collapse stays behind on
 * whatever entry slid into the slot. The entry the user folded up opens, and
 * one they never touched closes.
 *
 * These mount the real renderer and drive the real buttons, because the bug is
 * a lifecycle bug — it only appears once a reorder has gone through the
 * emit/narrow/re-render round trip.
 */
const visit = vi.fn();

vi.mock('@inertiajs/vue3', () => ({
    router: {
        visit: (...args: unknown[]) => visit(...args),
        post: vi.fn(),
        reload: vi.fn(),
        on: () => () => {},
    },
    usePage: () => ({
        props: {
            translations: {
                forms: {
                    remove: 'Remove',
                    no_entries: 'No entries yet.',
                    // The controls name the entry they act on, and the entry's
                    // name comes from the server — so the sentence around it
                    // is a template, not a string built in Vue.
                    move_item_up: 'Move :item up',
                    move_item_down: 'Move :item down',
                    remove_item: 'Remove :item',
                    move_block_up: 'Move block up',
                    move_block_down: 'Move block down',
                    remove_block: 'Remove block',
                },
            },
        },
        url: '/',
        component: '',
        version: null,
    }),
    Link: { name: 'Link', template: '<a><slot /></a>' },
}));

const { default: FormRenderer } =
    await import('@/panel/forms/FormRenderer.vue');

const mounted: Array<{ unmount: () => void }> = [];

afterEach(() => {
    while (mounted.length > 0) {
        mounted.pop()?.unmount();
    }

    document.body.innerHTML = '';
    visit.mockClear();
});

function field(overrides: Record<string, unknown> = {}): FieldDefinition {
    return {
        component: 'field',
        name: 'name',
        label: 'Name',
        type: 'text',
        inputType: 'text',
        maxLength: null,
        value: '',
        placeholder: null,
        helperText: null,
        required: false,
        disabled: false,
        inlineLabel: false,
        columnSpan: 'full',
        conditions: { visibleWhen: [], hiddenWhen: [] },
        live: null,
        validation: { required: false },
        ...overrides,
    } as unknown as FieldDefinition;
}

/** A collapsible, reorderable repeater of three named entries. */
function repeater(
    entries: string[] = ['A', 'B', 'C'],
    overrides: Record<string, unknown> = {},
): FieldDefinition {
    return field({
        name: 'contacts',
        label: 'Contacts',
        type: 'repeater',
        value: entries.map((name) => ({ name })),
        schema: [field({ name: 'name', label: 'Name' })],
        itemLabels: entries,
        emptyItem: { name: '' },
        addable: true,
        deletable: true,
        reorderable: true,
        collapsible: true,
        addLabel: 'Add',
        minItems: null,
        maxItems: null,
        ...overrides,
    });
}

function render(schema: FormComponentDefinition[]) {
    const wrapper = mount(FormRenderer, {
        attachTo: document.body,
        props: {
            form: { schema, columns: 1 },
            submitUrl: '/panel/things',
        },
    });

    mounted.push(wrapper);

    return wrapper;
}

type Wrapper = ReturnType<typeof render>;

/**
 * The entry headers, in the order they are drawn.
 *
 * A collapsible entry's header is the toggle button, and `aria-expanded` is
 * the state under test — the same attribute a screen reader reads.
 */
function headers(wrapper: Wrapper) {
    // The builder's block picker is also an `aria-expanded` button and is
    // rendered after the entries, so the list is cut to one per entry.
    const entries = wrapper.findAll('input').length;

    return wrapper
        .findAll('button')
        .filter((button) => button.attributes('aria-expanded') !== undefined)
        .slice(0, entries);
}

/**
 * Which entry sits in each slot, and whether it is folded up.
 *
 * Entries are identified by the value they hold, not by their header text.
 * The header renders `field.itemLabels[index]`, which the server computes per
 * position and which does not move when the client reorders — a separate
 * defect, recorded in the wave report rather than fixed here. The value is
 * what actually travels with the entry.
 *
 * A collapsed entry is hidden with `v-show`, so its input is still in the DOM
 * and still readable.
 */
function state(wrapper: Wrapper): Array<[string, boolean]> {
    const rows = headers(wrapper);
    const values = wrapper
        .findAll('input')
        .map((input) => (input.element as HTMLInputElement).value);

    return rows.map((header, index) => [
        values[index],
        header.attributes('aria-expanded') === 'false',
    ]);
}

function button(wrapper: Wrapper, label: string) {
    return wrapper
        .findAll('button')
        .filter((candidate) => candidate.attributes('aria-label') === label)[0];
}

/*
 * Phase 1 / Phase 3 — the reproduction, and what it should say instead
 */

describe('a collapsed entry that is moved', () => {
    it('travels with the entry when it moves down', async () => {
        const wrapper = render([repeater()]);

        await headers(wrapper)[1].trigger('click');

        expect(state(wrapper)).toEqual([
            ['A', false],
            ['B', true],
            ['C', false],
        ]);

        await button(wrapper, 'Move B down').trigger('click');
        await flushPromises();

        // Before this wave: the collapse stayed on position 1, so C — which
        // had merely slid up into B's slot — was drawn folded, and B, which
        // the user had folded, was drawn open.
        expect(state(wrapper)).toEqual([
            ['A', false],
            ['C', false],
            ['B', true],
        ]);
    });

    it('travels with the entry when it moves up', async () => {
        const wrapper = render([repeater()]);

        await headers(wrapper)[2].trigger('click');

        expect(state(wrapper)[2]).toEqual(['C', true]);

        await button(wrapper, 'Move C up').trigger('click');
        await flushPromises();

        expect(state(wrapper)).toEqual([
            ['A', false],
            ['C', true],
            ['B', false],
        ]);
    });

    it('stays put when a different entry moves past it', async () => {
        const wrapper = render([repeater()]);

        await headers(wrapper)[1].trigger('click');

        // C moves above B. Nothing about B changed except where it sits, so
        // nothing about B's collapse should change either.
        await button(wrapper, 'Move C up').trigger('click');
        await flushPromises();

        expect(state(wrapper)).toEqual([
            ['A', false],
            ['C', false],
            ['B', true],
        ]);
    });
});

/*
 * Phase 4 / Phase 5 — an entry that goes away, and one that arrives
 */

describe('an entry that is removed', () => {
    it('takes its collapse with it', async () => {
        const wrapper = render([repeater()]);

        await headers(wrapper)[1].trigger('click');
        await button(wrapper, 'Remove B').trigger('click');
        await flushPromises();

        expect(state(wrapper)).toEqual([
            ['A', false],
            ['C', false],
        ]);
    });

    it('does not leave the collapse for the next entry to inherit', async () => {
        const wrapper = render([repeater()]);

        await headers(wrapper)[1].trigger('click');
        await button(wrapper, 'Remove B').trigger('click');
        await flushPromises();

        // The new entry lands in the slot the collapsed one used to occupy.
        // Under index keying it would arrive folded up, for no reason the user
        // could see.
        await wrapper
            .findAll('button')
            .filter((candidate) => candidate.text() === 'Add')[0]
            .trigger('click');
        await flushPromises();

        expect(state(wrapper)).toEqual([
            ['A', false],
            ['C', false],
            ['', false],
        ]);
    });

    it('drops the collapse when the entry disappears from underneath', async () => {
        const wrapper = render([repeater()]);

        await headers(wrapper)[2].trigger('click');

        // The server rebuilt the form with a shorter list — a `live()` answer,
        // or a fresh page. The identity is gone and so is what was remembered
        // about it.
        await wrapper.setProps({
            form: {
                schema: [repeater(['A', 'B'])],
                columns: 1,
            },
        });
        await flushPromises();

        await wrapper
            .findAll('button')
            .filter((candidate) => candidate.text() === 'Add')[0]
            .trigger('click');
        await flushPromises();

        expect(state(wrapper).at(-1)).toEqual(['', false]);
    });
});

/*
 * Phase 7 — the identity has to survive the form narrowing every value
 */

describe('an entry that is edited', () => {
    it('stays collapsed while its own value changes', async () => {
        const wrapper = render([repeater()]);

        await headers(wrapper)[1].trigger('click');

        // A collapsed entry is hidden rather than unmounted, so its input is
        // still writable — and every keystroke goes through `toFormValue`,
        // which rebuilds every entry object in the list. An identity derived
        // from the object would be regenerated here, on every character.
        await wrapper.findAll('input')[1].setValue('Bee');
        await flushPromises();

        expect(state(wrapper)).toEqual([
            ['A', false],
            ['Bee', true],
            ['C', false],
        ]);
    });

    it('stays collapsed while a different entry changes', async () => {
        const wrapper = render([repeater()]);

        await headers(wrapper)[1].trigger('click');

        await wrapper.findAll('input')[0].setValue('Ada');
        await flushPromises();

        expect(state(wrapper)).toEqual([
            ['Ada', false],
            ['B', true],
            ['C', false],
        ]);
    });

    it('keeps its DOM identity across an edit', async () => {
        const wrapper = render([repeater()]);

        const before = wrapper
            .findAll('input')
            .map((input) => input.attributes('id'));

        await wrapper.findAll('input')[1].setValue('Bee');
        await flushPromises();

        const after = wrapper
            .findAll('input')
            .map((input) => input.attributes('id'));

        expect(after).toEqual(before);
    });
});

/*
 * Phase 8 — U02 must still hold, and must not have been traded away
 */

describe('the DOM identity U02 established', () => {
    it('stays unique after a reorder', async () => {
        const wrapper = render([repeater()]);

        const before = wrapper
            .findAll('input')
            .map((input) => input.attributes('id'));

        await button(wrapper, 'Move B down').trigger('click');
        await flushPromises();

        const after = wrapper
            .findAll('input')
            .map((input) => input.attributes('id'));

        expect(new Set(after).size).toBe(3);
        expect(after).toEqual([before[0], before[2], before[1]]);
    });

    it('keeps every label pointing at its own entry after a reorder', async () => {
        const wrapper = render([repeater()]);

        await button(wrapper, 'Move B down').trigger('click');
        await flushPromises();

        const inputs = wrapper.findAll('input');
        const labels = wrapper
            .findAll('label')
            .filter((label) => label.attributes('for') !== undefined)
            .slice(-3);

        expect(labels.map((label) => label.attributes('for'))).toEqual(
            inputs.map((input) => input.attributes('id')),
        );
    });

    it('leaves the submitted payload keyed by position', async () => {
        const wrapper = render([repeater()]);

        await button(wrapper, 'Move B down').trigger('click');
        await flushPromises();
        await wrapper.get('form').trigger('submit');

        const [, options] = visit.mock.calls.at(-1) as [
            string,
            { data: Record<string, unknown> },
        ];

        // The payload is a list, and its order is the order on screen. The
        // identity is a rendering detail and does not appear here at all.
        expect(options.data.contacts).toEqual([
            { name: 'A' },
            { name: 'C' },
            { name: 'B' },
        ]);
    });
});

/*
 * The label an entry wears
 */

describe('the name an entry is given', () => {
    it('moves with the entry', async () => {
        const wrapper = render([repeater()]);

        await button(wrapper, 'Move B down').trigger('click');
        await flushPromises();

        // `itemLabels` arrives indexed by position, and was read back the same
        // way — so after a move the header named the entry's neighbour.
        expect(headers(wrapper).map((header) => header.text().trim())).toEqual([
            'A',
            'C',
            'B',
        ]);
    });

    it('keeps a destructive control honest after a removal', async () => {
        const wrapper = render([repeater()]);

        await button(wrapper, 'Remove B').trigger('click');
        await flushPromises();

        const names = wrapper
            .findAll('button')
            .map((candidate) => candidate.attributes('aria-label'))
            .filter(
                (name): name is string => name?.startsWith('Remove') ?? false,
            );

        // The worst version of the same bug: after removing B, the button that
        // deletes C was still called "Remove B". Somebody operating by name
        // would have been told they were deleting the entry they had just
        // deleted.
        expect(names).toEqual(['Remove A', 'Remove C']);
    });

    it('falls back to a position for an entry the server never named', async () => {
        const wrapper = render([repeater()]);

        await wrapper
            .findAll('button')
            .filter((candidate) => candidate.text() === 'Add')[0]
            .trigger('click');
        await flushPromises();

        expect(headers(wrapper).at(-1)?.text().trim()).toBe('Item 4');
    });

    it("takes the server's word again when a new payload arrives", async () => {
        const wrapper = render([repeater()]);

        await button(wrapper, 'Move B down').trigger('click');
        await flushPromises();

        // A rebuilt schema is authoritative: the server walked the values it
        // was sent, in their current order, so its labels are re-paired by
        // position at that moment and by identity from then on.
        await wrapper.setProps({
            form: { schema: [repeater(['one', 'two', 'three'])], columns: 1 },
        });
        await flushPromises();

        expect(headers(wrapper).map((header) => header.text().trim())).toEqual([
            'one',
            'two',
            'three',
        ]);
    });
});

/*
 * The same defect in the other repeating container
 */

function builder(entries: string[] = ['A', 'B', 'C']): FieldDefinition {
    return field({
        name: 'blocks',
        label: 'Blocks',
        type: 'builder',
        value: entries.map((name) => ({ type: 'text', data: { name } })),
        blocks: [
            {
                name: 'text',
                label: 'Text',
                icon: null,
                emptyData: { name: '' },
                schema: [field({ name: 'name', label: 'Name' })],
            },
        ],
        minItems: null,
        maxItems: null,
        reorderable: true,
        collapsible: true,
        addLabel: 'Add block',
    });
}

describe('a collapsed block in a builder', () => {
    it('travels with the block when it moves', async () => {
        const wrapper = render([builder()]);

        await headers(wrapper)[1].trigger('click');

        expect(state(wrapper)).toEqual([
            ['A', false],
            ['B', true],
            ['C', false],
        ]);

        // The builder is the repeater's sibling and carried the identical
        // `Set<number>`; fixing one and not the other would leave the finding
        // half closed.
        const down = wrapper
            .findAll('button')
            .filter(
                (candidate) =>
                    candidate.attributes('aria-label') === 'Move block down',
            );

        await down[1].trigger('click');
        await flushPromises();

        expect(state(wrapper)).toEqual([
            ['A', false],
            ['C', false],
            ['B', true],
        ]);
    });

    it('takes its collapse with it when the block is removed', async () => {
        const wrapper = render([builder()]);

        await headers(wrapper)[1].trigger('click');

        const remove = wrapper
            .findAll('button')
            .filter(
                (candidate) =>
                    candidate.attributes('aria-label') === 'Remove block',
            );

        await remove[1].trigger('click');
        await flushPromises();

        expect(state(wrapper)).toEqual([
            ['A', false],
            ['C', false],
        ]);
    });
});

/*
 * F01 — where focus goes when an entry is deleted
 */

function activeLabel(): string | null {
    const element = document.activeElement as HTMLElement | null;

    return (
        element?.getAttribute('aria-label') ??
        element?.textContent?.trim() ??
        null
    );
}

describe('focus after removing an entry', () => {
    it('lands on the next entry, not on its Remove button', async () => {
        const wrapper = render([repeater()]);

        const remove = button(wrapper, 'Remove B');

        (remove.element as HTMLElement).focus();
        await remove.trigger('click');
        await flushPromises();

        // The DOM node that held "Remove B" is reused by the entry that moved
        // up into the slot, and focus stayed on it — leaving the user one
        // press away from deleting an entry they never chose.
        expect(activeLabel()).not.toContain('Remove');
        expect(activeLabel()).toBe('C');
    });

    it('lands on the previous entry when the last one goes', async () => {
        const wrapper = render([repeater()]);

        const remove = button(wrapper, 'Remove C');

        (remove.element as HTMLElement).focus();
        await remove.trigger('click');
        await flushPromises();

        expect(activeLabel()).toBe('B');
    });

    it('lands on the add control when nothing survives', async () => {
        const wrapper = render([repeater(['only'])]);

        const remove = button(wrapper, 'Remove only');

        (remove.element as HTMLElement).focus();
        await remove.trigger('click');
        await flushPromises();

        expect(
            (document.activeElement as HTMLElement)?.hasAttribute(
                'data-repeat-add',
            ),
        ).toBe(true);
    });

    it('lands on the entry itself when it has no header control', async () => {
        // Not collapsible: the first button in the header is Move up, and if
        // reordering were off too it would be Remove. The container is the
        // only target that is safe whatever the entry is made of.
        const wrapper = render([
            repeater(['A', 'B', 'C'], {
                collapsible: false,
                reorderable: false,
            }),
        ]);

        const remove = button(wrapper, 'Remove B');

        (remove.element as HTMLElement).focus();
        await remove.trigger('click');
        await flushPromises();

        const active = document.activeElement as HTMLElement | null;

        expect(active?.hasAttribute('data-repeat-entry')).toBe(true);
        expect(active?.getAttribute('tabindex')).toBe('-1');
    });

    it('does the same in a builder', async () => {
        const wrapper = render([builder()]);

        const remove = wrapper
            .findAll('button')
            .filter(
                (candidate) =>
                    candidate.attributes('aria-label') === 'Remove block',
            )[1];

        (remove.element as HTMLElement).focus();
        await remove.trigger('click');
        await flushPromises();

        expect(activeLabel()).not.toContain('Remove');
        expect(activeLabel()).toBe('Text');
    });
});
