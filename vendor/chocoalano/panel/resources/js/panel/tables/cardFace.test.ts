import { describe, expect, it } from 'vitest';
import { resolveCardFace } from '@/panel/tables/cardFace';
import type { CardFaceDefinition, ColumnDefinition } from '@/panel/types/table';

/**
 * The half of the card face that lives in TypeScript.
 *
 * The server decides which column fills which slot; this decides which of
 * those slots the *reader* actually gets, from the column arrangement. The two
 * halves are checked against each other by name in `FrontendContractTest`,
 * which cannot see behaviour — these are the behaviour.
 *
 * The rule under test is the one that is easy to get backwards: image and
 * title ignore visibility because a card without them has no identity, and
 * description, badges and details respect it because they are the card's
 * equivalent of a row's cells.
 */

function column(name: string): ColumnDefinition {
    return {
        name,
        label: name,
        type: 'text',
        sortable: false,
        searchable: false,
        individuallySearchable: false,
        visible: true,
        toggleable: true,
        alignment: 'start',
        headerAlignment: 'start',
        placeholder: null,
        headerTooltip: null,
        wrapHeader: false,
        width: null,
        frozen: null,
        wrap: false,
    } as ColumnDefinition;
}

const COLUMNS = ['avatar', 'name', 'email', 'status', 'team'].map(column);

function face(overrides: Partial<CardFaceDefinition> = {}): CardFaceDefinition {
    return {
        columns: 3,
        image: 'avatar',
        title: 'name',
        description: 'email',
        badges: ['status'],
        details: ['team'],
        ...overrides,
    };
}

describe('resolveCardFace', () => {
    it('resolves every slot to the column definition behind it', () => {
        const resolved = resolveCardFace(face(), COLUMNS, [
            'avatar',
            'name',
            'email',
            'status',
            'team',
        ]);

        expect(resolved.image?.name).toBe('avatar');
        expect(resolved.title?.name).toBe('name');
        expect(resolved.description?.name).toBe('email');
        expect(resolved.badges.map((c) => c.name)).toEqual(['status']);
        expect(resolved.details.map((c) => c.name)).toEqual(['team']);
    });

    it('keeps the image and the title when the arrangement hides them', () => {
        // A card with no heading is not a card. These are the columns a table
        // normally marks `toggleable(false)` anyway.
        const resolved = resolveCardFace(face(), COLUMNS, ['team']);

        expect(resolved.image?.name).toBe('avatar');
        expect(resolved.title?.name).toBe('name');
    });

    it('drops the body slots the arrangement hides', () => {
        // The column manager is exactly the control for "which of these do I
        // want to see", and these are the card's cells.
        const resolved = resolveCardFace(face(), COLUMNS, ['avatar', 'name']);

        expect(resolved.description).toBeNull();
        expect(resolved.badges).toEqual([]);
        expect(resolved.details).toEqual([]);
    });

    it('drops only the hidden half of a multi-column slot', () => {
        const resolved = resolveCardFace(
            face({ details: ['team', 'email'] }),
            COLUMNS,
            ['team'],
        );

        expect(resolved.details.map((c) => c.name)).toEqual(['team']);
    });

    it('resolves a slot naming a column that is not there to null', () => {
        // The server validates this and throws, so it should be unreachable —
        // but a stale payload must render an incomplete card rather than throw
        // inside the grid.
        const resolved = resolveCardFace(
            face({ title: 'nonexistent', badges: ['also-missing'] }),
            COLUMNS,
            ['avatar', 'name', 'email', 'status', 'team', 'nonexistent'],
        );

        expect(resolved.title).toBeNull();
        expect(resolved.badges).toEqual([]);
    });

    it('handles a face with every slot empty', () => {
        const resolved = resolveCardFace(
            face({
                image: null,
                title: null,
                description: null,
                badges: [],
                details: [],
            }),
            COLUMNS,
            ['name'],
        );

        expect(resolved.image).toBeNull();
        expect(resolved.title).toBeNull();
        expect(resolved.description).toBeNull();
        expect(resolved.badges).toEqual([]);
        expect(resolved.details).toEqual([]);
    });

    it('never interpolates a grid class', () => {
        // An interpolated class is absent from the bundle and the run of cards
        // silently collapses to one column, so every count has to come from
        // the shared literal map.
        for (const columns of [1, 2, 3, 4]) {
            const resolved = resolveCardFace(face({ columns }), COLUMNS, []);

            expect(resolved.gridClasses).toContain('grid-cols-1');
            expect(resolved.gridClasses).not.toContain('${');
        }
    });

    it('falls back to one column for a count with no literal class', () => {
        // The server clamps to four, so this is the defence in depth rather
        // than the rule.
        const resolved = resolveCardFace(face({ columns: 99 }), COLUMNS, []);

        expect(resolved.gridClasses).toBe('grid-cols-1');
    });

    it('does not care about the order of the arrangement', () => {
        const forward = resolveCardFace(face(), COLUMNS, ['status', 'team']);
        const backward = resolveCardFace(face(), COLUMNS, ['team', 'status']);

        // Slot order comes from the face, not from the arrangement: a card
        // whose badges swapped places because somebody dragged a column would
        // be reordering the wrong thing.
        expect(forward.badges.map((c) => c.name)).toEqual(
            backward.badges.map((c) => c.name),
        );
    });
});
