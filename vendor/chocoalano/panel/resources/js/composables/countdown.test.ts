import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { effectScope, nextTick, ref } from 'vue';
import { useCountdown } from '@/composables/useCountdown';

/**
 * The wait before another code may be asked for.
 *
 * `retryAfter` was used exactly as the server sent it: the button said "wait
 * 30 seconds" and went on saying it, disabled, for as long as the page stayed
 * open. Nothing decremented it, so a user who waited the thirty seconds saw
 * precisely what they saw at the start.
 *
 * These use fake timers because the thing under test is time. They also move
 * the *clock* independently of the timer, which is the case a naive
 * decrementing counter gets wrong: a backgrounded tab stops receiving ticks
 * but does not stop time passing.
 */
let clock = 0;

const now = () => clock;

beforeEach(() => {
    clock = 1_700_000_000_000;
    vi.useFakeTimers();
});

afterEach(() => {
    vi.useRealTimers();
});

/** Advances the timer and the clock together, as real time does. */
async function advance(seconds: number): Promise<void> {
    clock += seconds * 1000;
    vi.advanceTimersByTime(seconds * 1000);
    await nextTick();
}

function run(seconds: number) {
    const scope = effectScope();
    const source = ref(seconds);
    let api!: ReturnType<typeof useCountdown>;

    scope.run(() => {
        api = useCountdown(source, now);
    });

    return { scope, source, api };
}

/*
 * U07 — U / V / W / X / Y
 */

describe('a resend cooldown', () => {
    it('counts down', async () => {
        const { api, scope } = run(3);

        expect(api.remaining.value).toBe(3);
        expect(api.active.value).toBe(true);

        await advance(1);

        expect(api.remaining.value).toBe(2);

        scope.stop();
    });

    it('releases the button when the wait is over', async () => {
        const { api, scope } = run(3);

        await advance(3);

        expect(api.remaining.value).toBe(0);
        expect(api.active.value).toBe(false);

        scope.stop();
    });

    it('is not waiting at all when the server said zero', () => {
        const { api, scope } = run(0);

        expect(api.active.value).toBe(false);

        scope.stop();
    });

    it('catches up after the page stopped receiving ticks', async () => {
        const { api, scope } = run(30);

        // Twenty seconds pass with a single timer callback — a backgrounded
        // tab, a throttled timer, a laptop asleep. A counter decremented once
        // per tick would say 29 here, and would go on being wrong by nineteen
        // seconds until the page was reloaded.
        clock += 20_000;
        vi.advanceTimersByTime(1000);
        await nextTick();

        expect(api.remaining.value).toBe(10);

        scope.stop();
    });

    it('restarts from what the server sent next', async () => {
        const { api, source, scope } = run(3);

        await advance(3);

        expect(api.active.value).toBe(false);

        // Asking for another code re-renders the page with a fresh
        // `retryAfter`. That value is the authority; nothing here invents one.
        source.value = 60;
        await nextTick();

        expect(api.remaining.value).toBe(60);
        expect(api.active.value).toBe(true);

        scope.stop();
    });

    it('does not restart itself when the value is unchanged', async () => {
        const { api, source, scope } = run(30);

        await advance(10);
        expect(api.remaining.value).toBe(20);

        // A failed resend that comes back with the same number must not be
        // read as a new thirty seconds — that would let a failing endpoint
        // extend the wait indefinitely.
        source.value = 30;
        await nextTick();

        expect(api.remaining.value).toBe(20);

        scope.stop();
    });

    it('stops its timer when it goes away', () => {
        const { scope } = run(30);

        expect(vi.getTimerCount()).toBe(1);

        scope.stop();

        expect(vi.getTimerCount()).toBe(0);
    });
});

describe('a new answer that says the same thing', () => {
    it('starts the wait again when asked to', async () => {
        const { api, scope } = run(60);

        await advance(60);

        expect(api.active.value).toBe(false);

        // The rate limit is a fixed sixty seconds, so a successful resend
        // sends sixty again — the same number, and therefore not a change any
        // watcher can see. Without an explicit restart the button would stay
        // enabled through a wait that had just begun.
        api.restart();
        await nextTick();

        expect(api.remaining.value).toBe(60);
        expect(api.active.value).toBe(true);

        scope.stop();
    });
});
