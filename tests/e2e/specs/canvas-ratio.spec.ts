import type { Page } from '@playwright/test';
import { expect, restUrl, test } from '../fixtures';
import {
	connectFixtureStore,
	createProduct,
	PERSONALISED,
} from '../personalised';

/** mini-poster's declared 420:297, at A4. */
const A4 = { widthMm: 297, heightMm: 210, label: 'A4' };

/** A size within mini-poster's 5% ratio tolerance, but not its ratio. */
const WIDE = { widthMm: 300, heightMm: 205, label: 'Wide' };

/** How far the preview's width per unit of height may be from the one
 *  expected: the SDK rounds its own sizes to whole pixels. */
const RATIO_TOLERANCE = 0.01;

const FRAME = '.pixonaut-preview__frame[data-pixonaut-side="design"]';

/** The configuration version that stores the canvas shape; an older
 *  document is read as following its template. */
const CANVAS_RATIO_VERSION = 11;

/** mini-poster on no blank, sold at A4 and Wide, drawn as `draw` says. */
function poster(draw: boolean) {
	return {
		...PERSONALISED,
		v: CANVAS_RATIO_VERSION,
		blank: null,
		drawAtTemplateRatio: draw,
		sides: [
			{
				key: 'design',
				label: 'Poster',
				placement: null,
				template: { baseId: 'mini-poster', version: 1, name: 'Mini poster' },
				printSizes: [A4, WIDE],
			},
		],
	};
}

test.beforeEach(async ({ request, baseURL }) => {
	await connectFixtureStore(request, baseURL ?? '');
});

test.afterEach(async ({ request }) => {
	await request.delete(restUrl('/pixonaut-e2e/v1/connection'));
});

/** The preview side's width per unit of height. */
async function previewRatio(page: Page): Promise<number> {
	const found = await page
		.locator(`${FRAME} .pixonaut-preview__side`)
		.boundingBox();
	expect(found, 'the preview is laid out').not.toBeNull();
	return found ? found.width / found.height : 0;
}

/** Picks a print size in the designer's own size picker. */
async function pickSize(page: Page, label: string): Promise<void> {
	await page
		.locator('#pixonaut-form-design')
		.getByRole('radio', { name: new RegExp(label) })
		.click();
}

for (const draw of [true, false]) {
	test(`a product drawing at ${draw ? 'the template’s' : 'the chosen size’s'} ratio ${draw ? 'keeps' : 'changes'} the canvas shape when the size changes`, async ({
		page,
		request,
		theme,
	}) => {
		const permalink = await createProduct(
			request,
			`Canvas ${draw ? 'template' : 'size'} ${theme} ${Date.now()}`,
			poster(draw),
		);
		await page.goto(permalink);
		await expect(page.locator(FRAME)).toHaveClass(/is-drawn/);
		const declared = A4.widthMm / A4.heightMm;
		expect(Math.abs((await previewRatio(page)) - declared)).toBeLessThanOrEqual(
			RATIO_TOLERANCE,
		);

		await pickSize(page, WIDE.label);

		const expected = draw ? declared : WIDE.widthMm / WIDE.heightMm;
		await expect
			.poll(async () => Math.abs((await previewRatio(page)) - expected), {
				message: draw
					? 'the canvas keeps the template’s shape'
					: 'the canvas takes the chosen size’s shape',
			})
			.toBeLessThanOrEqual(RATIO_TOLERANCE);
	});
}
