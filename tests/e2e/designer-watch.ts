import type { Page } from '@playwright/test';

/** The SDK's calls that mount a designer. */
const MOUNTING = ['mount', 'createDesigner'];

/** The boxes a switch must keep the size of, by name: each a selector. */
export type Boxes = Record<string, string>;

/** One painted frame: each box's height, by name. */
export type Sample<B extends Boxes = Boxes> = Record<keyof B, number>;

/** Counts the designers the page mounts in `window.mounts`: the SDK's
 *  script defines its global, and each call through it that mounts one is
 *  noted, on the server's reads or without them. Call before the page
 *  loads. */
export async function countMounts(page: Page): Promise<void> {
	await page.addInitScript((MOUNTING: string[]) => {
		const counted = window as unknown as { mounts: number };
		counted.mounts = 0;
		let sdk: object | undefined;
		Object.defineProperty(window, 'Pixonaut', {
			configurable: true,
			get: () => sdk,
			set: (loaded: object) => {
				sdk = new Proxy(loaded, {
					get(target, key) {
						const value = Reflect.get(target, key);
						if (
							!MOUNTING.includes(String(key)) ||
							typeof value !== 'function'
						) {
							return value;
						}
						return (...args: unknown[]) => {
							counted.mounts++;
							return value.apply(target, args);
						};
					},
				});
			},
		});
	}, MOUNTING);
}

/** The designers mounted since the page loaded (`countMounts()`). */
export async function mounts(page: Page): Promise<number> {
	return page.evaluate(() => (window as unknown as { mounts: number }).mounts);
}

/** Starts noting, at every painted frame, the height of each box. */
export async function watchBoxes(page: Page, boxes: Boxes): Promise<void> {
	await page.evaluate((boxes) => {
		const samples: Array<Record<string, number>> = [];
		(window as unknown as { samples: typeof samples }).samples = samples;
		const read = (): void => {
			samples.push(
				Object.fromEntries(
					Object.entries(boxes).map(([name, selector]) => [
						name,
						document.querySelector(selector)?.getBoundingClientRect().height ??
							0,
					]),
				),
			);
		};
		// Read as the frame is about to be painted: after every animation
		// frame callback and the layout, as a resize observer is told.
		const note = (): void => {
			const observer = new ResizeObserver(() => {
				observer.disconnect();
				read();
			});
			observer.observe(document.body);
			requestAnimationFrame(note);
		};
		requestAnimationFrame(note);
	}, boxes);
}

/** Every frame noted since `watchBoxes()`. */
export async function samples<B extends Boxes>(
	page: Page,
	_boxes: B,
): Promise<Sample<B>[]> {
	return page.evaluate(
		() => (window as unknown as { samples: Sample<B>[] }).samples,
	);
}
