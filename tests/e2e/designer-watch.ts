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
	/** When it was painted, on the page's clock. */
	time: number;
	/** Whether Add to cart shows as disabled. */
	disabled: boolean;
	/** Whether a block over the form (WooCommerce's `.blockUI`) or the
	 *  preview's spinner shows. */
	blocked: boolean;
	spinner: boolean;
	/** The variation the form holds; '' for none. */
	variation: string;
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
				const cart = [
					...document.querySelectorAll('.single_add_to_cart_button'),
				].filter(shows);
				frames.push({
					time: performance.now(),
					disabled: cart.some((button) =>
						button.classList.contains('disabled'),
					),
					blocked: [...document.querySelectorAll('.blockUI')].some(shows),
					spinner: [...document.querySelectorAll('.pixonaut-spinner')].some(
						(spinner) =>
							shows(spinner) && getComputedStyle(spinner).display !== 'none',
					),
					variation:
						document.querySelector<HTMLInputElement>(
							'form [name="variation_id"]',
						)?.value ?? '',
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
					cart: cart.length > 0,
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

/** One layout shift the page reported: its score, when, and how each node
 *  it moved moved. */
export type Shift = {
	value: number;
	time: number;
	sources: Array<{
		node: string;
		/** Whether it is in the form's table of attributes, whose columns
		 *  fit the labels of the rows it shows. */
		inAttributes: boolean;
		previous: { x: number; y: number; width: number; height: number };
		current: { x: number; y: number; width: number; height: number };
	}>;
};

/** Starts noting each layout shift the page reports with no input of the
 *  shopper's just before it, from now on. */
export async function watchShifts(page: Page): Promise<void> {
	await page.evaluate(() => {
		const shifts: Shift[] = [];
		(window as unknown as { shifts: Shift[] }).shifts = shifts;
		type Entry = PerformanceEntry & {
			value: number;
			hadRecentInput: boolean;
			sources: Array<{
				node: Node | null;
				previousRect: DOMRectReadOnly;
				currentRect: DOMRectReadOnly;
			}>;
		};
		// The node, and the elements around it up to one with an id.
		const describe = (node: Node | null): string => {
			const names: string[] = [];
			let element =
				node instanceof Element ? node : (node?.parentElement ?? null);
			if (!(node instanceof Element)) {
				names.push(String(node?.nodeName));
			}
			while (element !== null && names.length < 4) {
				names.push(
					element.id === ''
						? `${element.tagName.toLowerCase()}.${[...element.classList].join('.')}`
						: `#${element.id}`,
				);
				if (element.id !== '') {
					break;
				}
				element = element.parentElement;
			}
			return names.join(' < ');
		};
		const rect = (box: DOMRectReadOnly) => ({
			x: box.x,
			y: box.y,
			width: box.width,
			height: box.height,
		});
		new PerformanceObserver((list) => {
			for (const entry of list.getEntries() as Entry[]) {
				if (entry.hadRecentInput) {
					continue;
				}
				shifts.push({
					value: entry.value,
					time: entry.startTime,
					sources: entry.sources.map((source) => ({
						node: describe(source.node),
						inAttributes:
							(source.node instanceof Element
								? source.node
								: (source.node?.parentElement ?? null)
							)?.closest('table.variations') != null,
						previous: rect(source.previousRect),
						current: rect(source.currentRect),
					})),
				});
			}
		}).observe({ type: 'layout-shift' });
	});
}

/** The layout shifts noted since `watchShifts()`, from `since` on. */
export async function shiftsSince(page: Page, since: number): Promise<Shift[]> {
	return page.evaluate(
		(since) =>
			(window as unknown as { shifts: Shift[] }).shifts.filter(
				(shift) => shift.time >= since,
			),
		since,
	);
}
