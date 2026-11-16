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

/** Where the storefront script shows the chosen variation's price: a
 *  theme's target, else the classic or the block template's product price
 *  (`price.ts`). */
const PRICE_SELECTOR =
	'[data-pixonaut-price], .summary .price, .wp-block-woocommerce-product-price';

/** One painted frame of a switch, as the shopper sees it: the pictures in
 *  the preview and its view strip that show as broken, whether Add to cart
 *  shows, and the variation's price shown. */
export type SwitchFrame = {
	broken: string[];
	cart: boolean;
	price: string;
	/** Whether the preview still shows the last designer's picture. */
	held: boolean;
	/** The heights of the side's preview and form. */
	preview: number;
	form: number;
};

/** Starts noting, at every painted frame, what `SwitchFrame` names: the
 *  preview and the strip are `#pixonaut-preview-<side>` and
 *  `#pixonaut-views-<side>`. */
export async function watchSwitch(page: Page, side: string): Promise<void> {
	await page.evaluate(
		([side, PRICE_SELECTOR]) => {
			const frames: SwitchFrame[] = [];
			(window as unknown as { switchFrames: typeof frames }).switchFrames =
				frames;
			const shows = (element: Element | null): boolean =>
				element !== null &&
				element.getClientRects().length > 0 &&
				getComputedStyle(element).visibility !== 'hidden';
			const read = (): void => {
				const pictures = [
					...document.querySelectorAll<HTMLImageElement>(
						`#pixonaut-preview-${side} img, #pixonaut-views-${side} img`,
					),
				];
				const preview = document.getElementById(`pixonaut-preview-${side}`);
				frames.push({
					// The copy held while the side mounts again.
					held: preview?.querySelector('[data-pixonaut-held]') != null,
					preview: preview?.getBoundingClientRect().height ?? 0,
					form:
						document
							.getElementById(`pixonaut-form-${side}`)
							?.getBoundingClientRect().height ?? 0,
					// Loaded and still without pixels: a URL that failed.
					broken: pictures
						.filter(
							(img) =>
								img.getAttribute('src') !== null &&
								img.complete &&
								img.naturalWidth === 0 &&
								shows(img),
						)
						.map((img) => img.getAttribute('src') ?? ''),
					cart: [
						...document.querySelectorAll('.single_add_to_cart_button'),
					].some(shows),
					price:
						[...document.querySelectorAll(PRICE_SELECTOR)]
							.find(shows)
							?.textContent?.trim() ?? '',
				});
			};
			// As `watchBoxes()`: read as the frame is about to be painted.
			const note = (): void => {
				const observer = new ResizeObserver(() => {
					observer.disconnect();
					read();
				});
				observer.observe(document.body);
				requestAnimationFrame(note);
			};
			requestAnimationFrame(note);
		},
		[side, PRICE_SELECTOR] as const,
	);
}

/** Every frame noted since `watchSwitch()`. */
export async function switchFrames(page: Page): Promise<SwitchFrame[]> {
	return page.evaluate(
		() => (window as unknown as { switchFrames: SwitchFrame[] }).switchFrames,
	);
}
