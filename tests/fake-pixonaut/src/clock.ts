// The fake's notion of now. Tests move it instead of waiting for expiries:
// the idempotency window, link lifetimes, proof expiry.

export type Clock = {
	now(): number;
	/** Make `now()` answer `at` (epoch ms) from here on, still ticking. */
	set(at: number): void;
	advance(ms: number): void;
	reset(): void;
};

export function createClock(realNow: () => number = Date.now): Clock {
	let offset = 0;
	return {
		now: () => realNow() + offset,
		set(at) {
			offset = at - realNow();
		},
		advance(ms) {
			offset += ms;
		},
		reset() {
			offset = 0;
		},
	};
}
