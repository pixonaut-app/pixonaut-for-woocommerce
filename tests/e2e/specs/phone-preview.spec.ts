// The preview at phone width: as wide as the form under it up to a cap on its
// height, and pinned above the form only where it leaves the form room.
import type { APIRequestContext, Page } from '@playwright/test';
import { FIXTURE } from '../../fake-pixonaut/src/fixture-ids';
import { expect, restUrl, test } from '../fixtures';
import {
	connectFixtureStore,
	createProduct,
	PERSONALISED,
} from '../personalised';

const FRAME = '.pixonaut-preview__frame[data-pixonaut-side="design"]';
const PREVIEW = '.pixonaut-preview';
const FORM = '#pixonaut-form-design [data-testid="pixonaut-form"]';

/** The phones a 5:7 design has to fill the width of. */
const PHONES = [
	{ width: 390, height: 844 },
	{ width: 375, height: 667 },
];

const PHONE = { width: 390, height: 844 };

/** How far, in CSS pixels, two sizes may differ and be the same: the SDK
 *  rounds its own sizes. */
const SAME_SIZE_PX = 2;

/** The cap on the frame's height (`storefront.css`), as a share of the
 *  screen's. */
const HEIGHT_CAP = 0.8;

/** The part of a phone's screen an open keyboard leaves. */
const ABOVE_KEYBOARD = 0.55;

/** The template every product here prints, which a test gives its shape. */
const TEMPLATE = FIXTURE.template.landscape;

/** A shape as the fake's template takes it, and a print size of that shape. */
type Shape = { w: number; h: number };

const LANDSCAPE: Shape = { w: 420, h: 297 };
const PORTRAIT: Shape = { w: 500, h: 700 };
const TALL: Shape = { w: 300, h: 900 };

/** The template at `shape` on no blank, sold at one size of that shape. */
function poster({ w, h }: Shape) {
	return {
		...PERSONALISED,
		blank: null,
		sides: [
			{
				key: 'design',
				label: 'Design',
				placement: null,
				template: { baseId: 'mini-poster', version: 1, name: 'Mini poster' },
				printSizes: [{ widthMm: w, heightMm: h, label: `${w} × ${h} mm` }],
			},
		],
	};
}

/**
 * Gives the template `shape` and connects the store, whose connecting clears
 * the template read it keeps; the permalink of a product printing it.
 */
async function productOfShape(
	fake: { control: APIRequestContext },
	request: APIRequestContext,
	baseURL: string | undefined,
	shape: Shape,
	name: string,
): Promise<string> {
	const changed = await fake.control.post(
		`/__fake/templates/${TEMPLATE}/ratio`,
		{ data: shape },
	);
	expect(changed.ok(), 'the fake takes the shape').toBe(true);
	await connectFixtureStore(request, baseURL ?? '');
	return createProduct(request, name, poster(shape));
}

test.afterEach(async ({ request }) => {
	await request.delete(restUrl('/pixonaut-e2e/v1/connection'));
});

async function width(page: Page, selector: string): Promise<number> {
	const found = await page.locator(selector).first().boundingBox();
	expect(found, `${selector} is laid out`).not.toBeNull();
	return found?.width ?? 0;
}

/**
 * Stands in for the browser's visual viewport, which only a real on-screen
 * keyboard shrinks while the layout viewport stays as it is.
 */
async function fakeVisualViewport(page: Page): Promise<void> {
	await page.addInitScript(() => {
		const fake = Object.assign(new EventTarget(), {
			height: window.innerHeight,
		});
		Object.defineProperty(window, 'visualViewport', { value: fake });
	});
}

/** Leaves `share` of the screen visible, and tells the page. */
async function setKeyboard(page: Page, share: number): Promise<void> {
	await page.evaluate((visible) => {
		const viewport = window.visualViewport as unknown as EventTarget & {
			height: number;
		};
		viewport.height = window.innerHeight * visible;
		viewport.dispatchEvent(new Event('resize'));
	}, share);
}

/** Focuses each field in turn: it can be scrolled whole into what an open
 *  keyboard leaves of the screen, with nothing over it. */
async function expectFieldsAboveKeyboard(page: Page): Promise<void> {
	// An upload's file input is a 1px box for its button, not somewhere to type.
	const fields = page.locator(
		`${FORM} input:not([type="file"]):visible, ${FORM} textarea:visible`,
	);
	const count = await fields.count();
	expect(count, 'the form has fields').toBeGreaterThan(0);
	for (let index = 0; index < count; index++) {
		const shown = await fields.nth(index).evaluate((field, aboveKeyboard) => {
			(field as HTMLElement).focus();
			field.scrollIntoView({ block: 'start' });
			const box = field.getBoundingClientRect();
			const top = document.elementFromPoint(
				box.left + box.width / 2,
				box.top + box.height / 2,
			);
			return {
				onTop: top !== null && (field === top || field.contains(top)),
				aboveKeyboard: box.bottom <= window.innerHeight * aboveKeyboard,
			};
		}, ABOVE_KEYBOARD);
		expect(shown, `field ${index}`).toEqual({
			onTop: true,
			aboveKeyboard: true,
		});
	}
}

for (const viewport of PHONES) {
	test(`a 5:7 design spans the form's width on a ${viewport.width} x ${viewport.height} phone`, async ({
		page,
		request,
		theme,
		fake,
		baseURL,
	}) => {
		await page.setViewportSize(viewport);
		const permalink = await productOfShape(
			fake,
			request,
			baseURL,
			PORTRAIT,
			`Phone portrait ${theme} ${viewport.height} ${Date.now()}`,
		);
		await page.goto(permalink);
		await expect(page.locator(FRAME)).toHaveClass(/is-drawn/);
		await expect(page.locator(FORM)).toBeVisible();

		const frameWidth = await width(page, FRAME);
		const formWidth = await width(page, FORM);
		expect(Math.abs(frameWidth - formWidth)).toBeLessThanOrEqual(SAME_SIZE_PX);
		await expect(page.locator(PREVIEW)).toHaveCSS('position', 'static');
	});
}

test('a landscape design stays pinned above the form on a phone, until the keyboard opens', async ({
	page,
	request,
	theme,
	fake,
	baseURL,
}) => {
	await page.setViewportSize(PHONE);
	const permalink = await productOfShape(
		fake,
		request,
		baseURL,
		LANDSCAPE,
		`Phone landscape ${theme} ${Date.now()}`,
	);
	await fakeVisualViewport(page);
	await page.goto(permalink);
	await expect(page.locator(FRAME)).toHaveClass(/is-drawn/);
	await expect(page.locator(FORM)).toBeVisible();
	await expect(page.locator(PREVIEW)).toHaveCSS('position', 'sticky');

	await setKeyboard(page, ABOVE_KEYBOARD);
	await expect(page.locator(PREVIEW)).toHaveCSS('position', 'static');
	await expectFieldsAboveKeyboard(page);

	await setKeyboard(page, 1);
	await expect(page.locator(PREVIEW)).toHaveCSS('position', 'sticky');
});

test('a very tall design narrows to the cap, and every field can be reached with the keyboard open', async ({
	page,
	request,
	theme,
	fake,
	baseURL,
}) => {
	await page.setViewportSize(PHONE);
	const permalink = await productOfShape(
		fake,
		request,
		baseURL,
		TALL,
		`Phone tall ${theme} ${Date.now()}`,
	);
	await page.goto(permalink);
	await expect(page.locator(FRAME)).toHaveClass(/is-drawn/);
	await expect(page.locator(FORM)).toBeVisible();

	const frame = await page.locator(FRAME).boundingBox();
	const cap = PHONE.height * HEIGHT_CAP;
	expect(frame?.height ?? 0).toBeLessThanOrEqual(cap + SAME_SIZE_PX);
	expect(frame?.width ?? 0).toBeLessThan(await width(page, FORM));
	await expect(page.locator(PREVIEW)).toHaveCSS('position', 'static');

	await expectFieldsAboveKeyboard(page);
});
