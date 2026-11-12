import type { APIRequestContext, Request } from '@playwright/test';
import { FIXTURE } from '../../fake-pixonaut/src/fixture-ids';
import {
	countMounts,
	mounts,
	type Sample,
	samples,
	watchBoxes,
} from '../designer-watch';
import { expect, restUrl, test } from '../fixtures';
import {
	addToCartButton,
	connectFixtureStore,
	PERSONALISED,
} from '../personalised';

/** mini-poster's 420:297 ratio at A4. */
const A4_LANDSCAPE = { widthMm: 297, heightMm: 210, label: 'A4 landscape' };

/** The tee's front, and the same front on mini-poster, which keeps the
 *  tee's field keys. */
const OVERRIDES = {
	Poster: {
		...PERSONALISED,
		sides: [
			{
				...PERSONALISED.sides[0],
				template: { baseId: 'mini-poster', version: 1, name: 'Mini poster' },
				printSizes: [A4_LANDSCAPE],
			},
		],
	},
};

const SIZES = ['Tee', ...Object.keys(OVERRIDES)];

/** The slogan's text, which both templates offer. */
const SLOGAN = 'slogan.content';

const SLOGAN_KEY = 'slogan';

/** What the shopper types before the switch. */
const TYPED = 'Carried over';

/** The boxes that must keep their size while the template switches. */
const BOXES = {
	preview: '#pixonaut-preview-front',
	form: '#pixonaut-form-front',
	views: '#pixonaut-views-front',
};

/** Painted frames sampled after the switch: well past the new template's
 *  read from the fake and its drawing. */
const SWITCH_FRAMES = 120;

/** A difference in height too small to see: layout rounds to whole
 *  pixels. */
const SAME_HEIGHT_PX = 1;

test.beforeEach(async ({ request, baseURL }) => {
	await connectFixtureStore(request, baseURL ?? '');
});

test.afterEach(async ({ request }) => {
	await request.delete(restUrl('/pixonaut-e2e/v1/connection'));
});

async function createProduct(
	request: APIRequestContext,
	name: string,
): Promise<string> {
	const response = await request.post(restUrl('/pixonaut-e2e/v1/products'), {
		data: {
			name,
			pixonaut: PERSONALISED,
			variations: SIZES,
			overrides: OVERRIDES,
			variant: FIXTURE.variant.teeWhiteS,
		},
	});
	expect(response.status()).toBe(201);
	return (await response.json()).permalink;
}

function isProof(request: Request): boolean {
	return (
		request.method() === 'POST' &&
		new URL(request.url()).pathname === '/v1/proofs'
	);
}

test('a variation on another template switches it in the designer without moving the page', async ({
	page,
	request,
	baseURL,
	theme,
	fake,
}) => {
	// Both templates' slogans share a key, so the slogan's value follows it.
	for (const template of [FIXTURE.template.live, FIXTURE.template.landscape]) {
		const keyed = await fake.control.post(
			`/__fake/templates/${template}/field-key`,
			{ data: { field: SLOGAN, key: SLOGAN_KEY } },
		);
		expect(keyed.ok(), 'the fake keys the slogan').toBe(true);
	}
	// Connecting again clears the template reads the store keeps.
	await connectFixtureStore(request, baseURL ?? '');
	await countMounts(page);
	await page.goto(
		await createProduct(request, `Template switch ${theme} ${Date.now()}`),
	);
	const sizes = page.locator('select[name="attribute_size"]');
	await sizes.selectOption('Tee');
	const field = page
		.locator(BOXES.form)
		.getByRole('textbox', { name: /Slogan/ })
		.first();
	await field.fill(TYPED);
	await expect(page.locator('.pixonaut-preview__frame')).toHaveClass(
		/is-drawn/,
	);
	const mounted = await mounts(page);
	expect(mounted).toBe(1);
	await watchBoxes(page, BOXES);
	await expect
		.poll(async () => (await samples(page, BOXES)).length)
		.toBeGreaterThan(0);
	const before = (await samples(page, BOXES)).at(-1) as Sample<typeof BOXES>;
	expect(before.preview, 'the preview is laid out').toBeGreaterThan(0);
	expect(before.form, 'the form is laid out').toBeGreaterThan(0);

	await sizes.selectOption('Poster');
	await expect
		.poll(async () => (await samples(page, BOXES)).length)
		.toBeGreaterThan(SWITCH_FRAMES);

	expect(await mounts(page)).toBe(mounted);
	const seen = await samples(page, BOXES);
	for (const name of Object.keys(BOXES) as Array<keyof typeof BOXES>) {
		// A box laid out empty, as a strip with no views, has no size to keep.
		const collapsed = seen.filter(
			(sample) => before[name] > 0 && sample[name] === 0,
		);
		expect(collapsed, `the ${name} keeps its size`).toEqual([]);
	}
	const moved = seen.filter(
		(sample) => Math.abs(sample.preview - before.preview) > SAME_HEIGHT_PX,
	);
	expect(moved, 'the preview keeps its height').toEqual([]);
	await expect(field).toHaveValue(TYPED);

	const proofMade = page.waitForRequest(isProof);
	await expect(addToCartButton(page)).not.toHaveClass(/\bdisabled\b/);
	await addToCartButton(page).click();
	expect((await proofMade).postDataJSON()).toMatchObject({
		templateId: FIXTURE.template.landscape,
	});
	await expect(page.getByText(/has been added to your cart/)).toBeVisible();
});
