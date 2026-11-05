import type { APIRequestContext, Page } from '@playwright/test';
import { expect, restUrl, test } from '../fixtures';
import { connectFixtureStore, PERSONALISED } from '../personalised';

/** Sign sizes within mini-poster's 5% ratio tolerance, each of a slightly
 *  different shape, as a variable product's sizes often are. */
const SMALL = { widthMm: 500, heightMm: 350, label: '50 × 35 cm' };
const MEDIUM = { widthMm: 700, heightMm: 500, label: '70 × 50 cm' };
const LARGE = { widthMm: 1000, heightMm: 700, label: '100 × 70 cm' };
const SIZES = [SMALL, MEDIUM, LARGE];

/** The configuration version that stores the canvas shape. */
const CANVAS_RATIO_VERSION = 11;

const FRAME = '.pixonaut-preview__frame[data-pixonaut-side="design"]';

/** How far two widths per unit of height may differ and be one shape: the
 *  SDK rounds its sizes to whole pixels. */
const SAME_SHAPE = 0.005;

/** Decimal places the drawn picture's shape matches its size's to: the
 *  SDK rounds its sizes to whole pixels. */
const DRAWN_DIGITS = 2;

/** A preview height that leaves the frame's width to its shape. */
const HELD_HEIGHT = '.pixonaut-preview { --pixonaut-preview-height: 200px; }';

/** Painted frames to wait after a size is drawn, to see that nothing
 *  changes after it. */
const SETTLE_FRAMES = 10;

/** A desktop's viewport, and a phone's, below the 768px breakpoint. */
const VIEWPORTS = [
	{ width: 1280, height: 800 },
	{ width: 390, height: 844 },
];

/** mini-poster on no blank, at `sizes`, drawn at each size's own ratio. */
function sign(sizes: typeof SIZES) {
	return {
		...PERSONALISED,
		v: CANVAS_RATIO_VERSION,
		blank: null,
		drawAtTemplateRatio: false,
		sides: [
			{
				key: 'design',
				label: 'Sign',
				placement: null,
				template: { baseId: 'mini-poster', version: 1, name: 'Mini poster' },
				printSizes: sizes,
			},
		],
	};
}

/** A variable product whose every variation prints at one of the sizes,
 *  opening on the small one; its permalink. */
async function createSign(
	request: APIRequestContext,
	name: string,
): Promise<string> {
	const response = await request.post(restUrl('/pixonaut-e2e/v1/products'), {
		data: {
			name,
			pixonaut: sign(SIZES),
			variations: SIZES.map((size) => size.label),
			default: SMALL.label,
			overrides: Object.fromEntries(
				SIZES.map((size) => [size.label, sign([size])]),
			),
		},
	});
	expect(response.status()).toBe(201);
	return (await response.json()).permalink;
}

/** One painted frame of the preview: the frame's shape, its picture's
 *  shape on the page, and the shape the SDK drew that picture at. */
type Sample = { frame: number; shown: number; drawn: number };

/** Starts noting, at every animation frame, the frame's shape and its
 *  picture's. */
async function watchFrames(page: Page): Promise<void> {
	await page.evaluate((selector) => {
		const samples: Sample[] = [];
		(window as unknown as { samples: Sample[] }).samples = samples;
		// Read as the frame is about to be painted: after every animation
		// frame callback and the layout, as a resize observer is told.
		const read = (): void => {
			const frame = document.querySelector<HTMLElement>(selector);
			// The SDK's preview draws one picture.
			const picture = frame?.querySelector<HTMLImageElement>(
				'.pixonaut-preview__side img',
			);
			const box = picture?.getBoundingClientRect();
			if (frame && picture && box && box.height > 0) {
				samples.push({
					frame: Number.parseFloat(
						frame.style.getPropertyValue('--pixonaut-preview-aspect'),
					),
					shown: box.width / box.height,
					drawn: picture.naturalWidth / picture.naturalHeight,
				});
			}
		};
		const note = (): void => {
			// A new observer is told of its element once, in this frame.
			const observer = new ResizeObserver(() => {
				observer.disconnect();
				read();
			});
			observer.observe(document.body);
			requestAnimationFrame(note);
		};
		requestAnimationFrame(note);
	}, FRAME);
}

async function samples(page: Page): Promise<Sample[]> {
	return page.evaluate(
		() => (window as unknown as { samples: Sample[] }).samples,
	);
}

for (const viewport of VIEWPORTS) {
	test(`switching to a size of another shape changes the preview once, at ${viewport.width}px`, async ({
		page,
		request,
		baseURL,
		theme,
	}) => {
		await connectFixtureStore(request, baseURL ?? '');
		await page.setViewportSize(viewport);
		const permalink = await createSign(
			request,
			`Size switch ${theme} ${viewport.width} ${Date.now()}`,
		);
		// A theme holding the preview at a fixed height, as the Pixonaut
		// Shop theme does, sizes the frame by its shape.
		await page.goto(permalink);
		await page.addStyleTag({ content: HELD_HEIGHT });
		await expect(page.locator(FRAME)).toHaveClass(/is-drawn/);
		await watchFrames(page);

		for (const size of [MEDIUM, LARGE, SMALL]) {
			const count = (await samples(page)).length;
			await page
				.locator('select[name^="attribute_"]')
				.selectOption({ label: size.label });
			await expect
				.poll(async () => (await samples(page)).at(-1)?.drawn ?? 0, {
					message: `${size.label} is drawn`,
				})
				.toBeCloseTo(size.widthMm / size.heightMm, DRAWN_DIGITS);
			await expect
				.poll(async () => (await samples(page)).length)
				.toBeGreaterThan(count + SETTLE_FRAMES);
		}

		// Each frame's shape changes in the frame the picture drawn at the new
		// size shows, never before it, and the picture is never shown at
		// another shape than it was drawn at.
		const seen = await samples(page);
		const early = seen.filter(
			(sample, index) =>
				index > 0 &&
				sample.frame !== seen[index - 1]?.frame &&
				sample.drawn === seen[index - 1]?.drawn,
		);
		const stretched = seen.filter(
			(sample) => Math.abs(sample.shown - sample.drawn) > SAME_SHAPE,
		);
		const reshaped = seen.filter(
			(sample, index) => index > 0 && sample.frame !== seen[index - 1]?.frame,
		);
		expect(reshaped.length).toBeGreaterThan(0);
		expect(early).toEqual([]);
		expect(stretched).toEqual([]);
	});
}

test.afterEach(async ({ request }) => {
	await request.delete(restUrl('/pixonaut-e2e/v1/connection'));
});
