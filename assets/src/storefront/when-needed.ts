/** How far outside the viewport a designer starts loading, so it is ready
 *  by the time the customer scrolls to it. */
const PRELOAD_MARGIN = '200px';

/** Events on the form that mean the customer is about to use it. */
const INTERACTIONS = ['pointerdown', 'focusin', 'keydown'] as const;

/**
 * Calls `start` once: when any of `elements` comes near the viewport, or on
 * the first interaction with one of them, whichever is first.
 */
export function whenNeeded(
	elements: readonly HTMLElement[],
	start: () => void,
): void {
	let started = false;
	const cleanups: Array<() => void> = [];
	const once = () => {
		if (started) {
			return;
		}
		started = true;
		for (const cleanup of cleanups) {
			cleanup();
		}
		start();
	};
	for (const element of elements) {
		for (const type of INTERACTIONS) {
			element.addEventListener(type, once, { once: true });
			cleanups.push(() => element.removeEventListener(type, once));
		}
	}
	if (typeof IntersectionObserver === 'undefined') {
		once();
		return;
	}
	const observer = new IntersectionObserver(
		(entries) => {
			if (entries.some((entry) => entry.isIntersecting)) {
				once();
			}
		},
		{ rootMargin: PRELOAD_MARGIN },
	);
	for (const element of elements) {
		observer.observe(element);
	}
	cleanups.push(() => observer.disconnect());
}
