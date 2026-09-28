import { computed, onScopeDispose, ref, watch } from 'vue';
import type { ComputedRef, Ref } from 'vue';

/**
 * Seconds remaining, counted against a deadline rather than by ticking down.
 *
 * The difference matters. A counter decremented once a second is wrong the
 * moment the page stops receiving timer callbacks — a backgrounded tab, a
 * sleeping laptop, a browser throttling timers on a hidden page — and it is
 * wrong in the direction that keeps a button disabled after the wait is over.
 * A deadline is a fact: whatever happened to the timer, the answer on the next
 * tick is `deadline - now`, so twenty seconds asleep costs twenty seconds of
 * the wait rather than one tick of it.
 *
 * The interval exists only to make the number re-render. It is not the clock.
 */
export type UseCountdownReturn = {
    /** Whole seconds left, floored at zero. */
    remaining: ComputedRef<number>;
    active: ComputedRef<boolean>;
    /**
     * Arms the wait again from the current value.
     *
     * Needed because "the same number again" is a real answer. A second
     * request that is allowed and comes back with the same sixty seconds
     * changes nothing observable about the value, so a watcher never fires —
     * and the button would stay enabled through a wait that had just started.
     * The caller says when a new answer arrived; this says what to do with it.
     */
    restart: () => void;
};

export function useCountdown(
    seconds: Ref<number> | (() => number),
    /** Injected so a test can drive it; production passes nothing. */
    now: () => number = () => Date.now(),
): UseCountdownReturn {
    const read = typeof seconds === 'function' ? seconds : () => seconds.value;

    const deadline = ref(0);
    const tick = ref(now());

    let timer: ReturnType<typeof setInterval> | null = null;

    function stop(): void {
        if (timer !== null) {
            clearInterval(timer);
            timer = null;
        }
    }

    function start(): void {
        stop();

        timer = setInterval(() => {
            tick.value = now();

            if (tick.value >= deadline.value) {
                stop();
            }
        }, 1000);
    }

    function arm(): void {
        const value = read();

        tick.value = now();

        // Zero means the wait is over, which is a different statement from
        // "the server did not say" — it is said by the same field, so it
        // clears the deadline rather than being ignored.
        deadline.value = value > 0 ? tick.value + value * 1000 : 0;

        if (value > 0) {
            start();
        } else {
            stop();
        }
    }

    watch(read, arm, { immediate: true });

    onScopeDispose(stop);

    const remaining = computed(() =>
        Math.max(0, Math.ceil((deadline.value - tick.value) / 1000)),
    );

    return {
        remaining,
        active: computed(() => remaining.value > 0),
        restart: arm,
    };
}
