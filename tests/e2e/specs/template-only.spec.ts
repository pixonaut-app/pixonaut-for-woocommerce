import type { Page } from '@playwright/test';
import { FIXTURE } from '../../fake-pixonaut/src/fixture-ids';
import { expect, restUrl, test, usePlainPermalinks } from '../fixtures';
import {
	addToCartButton,
	connectFixtureStore,
	createProduct,
	PERSONALISED,
} from '../personalised';
import {
	openPixonautTab,
	pick,
	productPanel,
	publish,
	useOwnBlanks,
} from '../products';

/** mini-tee's artwork, which a print of it keeps the shape of. */
const MINI_TEE_RATIO = { w: 280, h: 360 };

/** How far the preview's width per unit of height may be from the
 *  template's: the SDK rounds its own sizes to whole pixels. */
const RATIO_TOLERANCE = 0.01;

/** A preview height a theme might hold, narrower in width than any theme's
 *  product column at the template's ratio, and short enough that it and the
 *  26px "Powered by Pixonaut" mark under it fit the 320px a short window
 *  still gives the frame. */
const HELD_HEIGHT_PX = 280;

const FRAME = '.pixonaut-preview__frame[data-pixonaut-side="design"]';

/** Desktop windows too short for a portrait preview at the column's width. */
const SHORT_WINDOWS = [
	{ width: 1345, height: 946 },
	{ width: 1280, height: 720 },
];

/** mini-tee on no blank, sold as a poster at its own ratio. */
const TEMPLATE_ONLY = {
	...PERSONALISED,
	blank: null,
	sides: [
		{
			key: 'design',
			label: 'Mini tee',
			placement: null,
			template: PERSONALISED.sides[0]?.template,
			printSizes: [
				{
					widthMm: MINI_TEE_RATIO.w,
					heightMm: MINI_TEE_RATIO.h,
					label: 'Poster',
				},
			],
		},
	],
};

test.beforeEach(async ({ request, baseURL }) => {
	await connectFixtureStore(request, baseURL ?? '');
});

test.afterEach(async ({ request }) => {
	await request.delete(restUrl('/pixonaut-e2e/v1/connection'));
});

/** The preview side's width per unit of height; the SDK's "Powered by
 *  Pixonaut" mark is drawn under it, outside. */
async function previewRatio(page: Page): Promise<number> {
	const side = page.locator(`${FRAME} .pixonaut-preview__side`);
	const found = await side.boundingBox();
	expect(found, 'the preview is laid out').not.toBeNull();
	return found ? found.width / found.height : 0;
}

test('a template-only product shows its template’s shape and joins the cart at its size', async ({
	page,
	request,
	theme,
}) => {
	const permalink = await createProduct(
		request,
		`Template-only poster ${theme} ${Date.now()}`,
		TEMPLATE_ONLY,
	);
	let release: () => void = () => undefined;
	const held = new Promise<void>((resolve) => {
		release = resolve;
	});
	await page.route('https://cdn.pixonaut.app/**', async (route) => {
		await held;
		await route.continue();
	});
	// The held SDK would hold the load event once the designer is in view.
	await page.goto(permalink, { waitUntil: 'domcontentloaded' });

	const expected = MINI_TEE_RATIO.w / MINI_TEE_RATIO.h;
	await expect(page.locator(FRAME)).toHaveAttribute(
		'style',
		new RegExp(
			`--pixonaut-preview-ratio: ${MINI_TEE_RATIO.w} / ${MINI_TEE_RATIO.h}`,
		),
	);
	expect(await previewRatio(page)).toBeCloseTo(expected, 2);

	release();
	await expect(page.locator(FRAME)).toHaveClass(/is-drawn/);
	expect(
		Math.abs((await previewRatio(page)) - expected),
		'the frame keeps the template’s shape once the SDK draws',
	).toBeLessThanOrEqual(RATIO_TOLERANCE);

	await addToCartButton(page).click();
	await expect(page.getByText(/has been added to your cart/)).toBeVisible();
});

test('editing a template-only design from the cart keeps the template’s shape', async ({
	page,
	request,
	theme,
}) => {
	const permalink = await createProduct(
		request,
		`Template-only edited ${theme} ${Date.now()}`,
		TEMPLATE_ONLY,
	);
	await page.goto(permalink);
	await addToCartButton(page).click();
	await expect(page).toHaveURL(/[?&]pixonaut_edit=[0-9a-f]+/);

	const expected = MINI_TEE_RATIO.w / MINI_TEE_RATIO.h;
	await expect(page.locator(FRAME)).toHaveAttribute(
		'style',
		new RegExp(
			`--pixonaut-preview-ratio: ${MINI_TEE_RATIO.w} / ${MINI_TEE_RATIO.h}`,
		),
	);
	await expect(page.locator(FRAME)).toHaveClass(/is-drawn/);
	expect(
		Math.abs((await previewRatio(page)) - expected),
		'the edited design’s preview is as wide as its shape, not square',
	).toBeLessThanOrEqual(RATIO_TOLERANCE);
});

for (const viewport of SHORT_WINDOWS) {
	test(`a portrait preview fits a ${viewport.width}x${viewport.height} window`, async ({
		page,
		request,
		theme,
	}) => {
		await page.setViewportSize(viewport);
		const permalink = await createProduct(
			request,
			`Template-only short window ${theme} ${Date.now()}`,
			TEMPLATE_ONLY,
		);
		await page.goto(permalink);
		await expect(page.locator(FRAME)).toHaveClass(/is-drawn/);

		const side = await page
			.locator(`${FRAME} .pixonaut-preview__side`)
			.boundingBox();
		expect(side, 'the preview is laid out').not.toBeNull();
		expect(
			(side?.y ?? 0) + (side?.height ?? 0),
			'the whole preview is in the window without scrolling',
		).toBeLessThanOrEqual(viewport.height);
	});
}

test('a theme holding previews at a height still shows a template-only side in its template’s shape', async ({
	page,
	request,
	theme,
}) => {
	const permalink = await createProduct(
		request,
		`Template-only held ${theme} ${Date.now()}`,
		TEMPLATE_ONLY,
	);
	await page.goto(permalink);
	// As a theme with a square gallery holds every preview.
	await page.addStyleTag({
		content: `.pixonaut-preview { --pixonaut-preview-height: ${HELD_HEIGHT_PX}px; }`,
	});
	await expect(page.locator(FRAME)).toHaveClass(/is-drawn/);

	const side = await page
		.locator(`${FRAME} .pixonaut-preview__side`)
		.boundingBox();
	expect(side?.height).toBeCloseTo(HELD_HEIGHT_PX, 0);
	expect(
		Math.abs((await previewRatio(page)) - MINI_TEE_RATIO.w / MINI_TEE_RATIO.h),
	).toBeLessThanOrEqual(RATIO_TOLERANCE);
});

test('warns of a background that shows through on a product without a blank, and not with one', async ({
	admin: page,
	fake,
	request,
	baseURL,
	theme,
}) => {
	test.skip(theme !== 'storefront', 'wp-admin does not depend on the theme');
	const told = await fake.control.post(
		`/__fake/templates/${FIXTURE.template.live}/background`,
		{ data: { seeThrough: true } },
	);
	expect(told.ok(), 'the fake takes the answer').toBe(true);
	// Connecting clears the template reads the store keeps.
	await connectFixtureStore(request, baseURL ?? '');
	await usePlainPermalinks(page, baseURL);
	await page.goto('/wp-admin/post-new.php?post_type=product');
	await page.locator('#title').fill(`See-through sign ${Date.now()}`);
	await page.locator('#_regular_price').fill('20');
	await openPixonautTab(page);
	const panel = productPanel(page);
	const warning = panel.locator(
		'.pixonaut-side[data-index="0"] .pixonaut-background-warning',
	);
	await panel.getByLabel('Personalise').check();
	await expect(warning).toBeHidden();

	// Linking such a template warns, and blocks nothing.
	await pick(page, 'pixonaut_side_0_template', 'shirt', 'T-shirt med tryck');
	await expect(warning).toBeVisible();
	const side = panel.locator('.pixonaut-side[data-index="0"]');
	await side.getByLabel('Width in millimetres').fill(`${MINI_TEE_RATIO.w}`);
	await side.getByLabel('Height in millimetres').fill(`${MINI_TEE_RATIO.h}`);
	await publish(page);
	await expect(page.locator('.pixonaut-notice')).toHaveCount(0);

	// A product already set up that way warns when opened.
	await openPixonautTab(page);
	await expect(warning).toBeVisible();

	// With a blank it no longer applies.
	await useOwnBlanks(page);
	await pick(page, 'pixonaut_blank', 'tee', 'Fixture tee');
	await expect(warning).toBeHidden();
});

test('the picture and the background warning follow the version a side sticks to', async ({
	admin: page,
	fake,
	request,
	baseURL,
	theme,
}) => {
	test.skip(theme !== 'storefront', 'wp-admin does not depend on the theme');
	const told = await fake.control.post(
		`/__fake/templates/${FIXTURE.template.previous}/background`,
		{ data: { seeThrough: true } },
	);
	expect(told.ok(), 'the fake takes the answer').toBe(true);
	await connectFixtureStore(request, baseURL ?? '');
	await usePlainPermalinks(page, baseURL);
	await page.goto('/wp-admin/post-new.php?post_type=product');
	await openPixonautTab(page);
	const panel = productPanel(page);
	await panel.getByLabel('Personalise').check();
	await pick(page, 'pixonaut_side_0_template', 'shirt', 'T-shirt med tryck');
	const side = panel.locator('.pixonaut-side[data-index="0"]');
	const warning = side.locator('.pixonaut-background-warning');
	const picture = page.locator(
		'img.pixonaut-chosen-thumbnail:has(+ #pixonaut_side_0_template)',
	);
	await expect(warning).toBeHidden();
	const latest = (await picture.getAttribute('src')) ?? '';
	expect(latest).not.toBe('');

	await side.locator('.pixonaut-side-more > summary').click();
	const stick = side.getByLabel('Stay on one version');
	await stick.check();
	await side
		.locator('.pixonaut-side-version')
		.selectOption({ label: 'Version 1' });
	await expect(warning).toBeVisible();
	await expect(picture).not.toHaveAttribute('src', latest);

	await stick.uncheck();
	await expect(warning).toBeHidden();
	await expect(picture).toHaveAttribute('src', latest);
});

test('does not warn of a background Pixonaut calls opaque or does not know', async ({
	admin: page,
	fake,
	request,
	baseURL,
	theme,
}) => {
	test.skip(theme !== 'storefront', 'wp-admin does not depend on the theme');
	const warning = page.locator(
		'.pixonaut-side[data-index="0"] .pixonaut-background-warning',
	);
	await usePlainPermalinks(page, baseURL);
	for (const seeThrough of [false, null]) {
		await fake.control.post(
			`/__fake/templates/${FIXTURE.template.live}/background`,
			{ data: { seeThrough } },
		);
		await connectFixtureStore(request, baseURL ?? '');
		await page.goto('/wp-admin/post-new.php?post_type=product');
		await openPixonautTab(page);
		await productPanel(page).getByLabel('Personalise').check();
		await pick(page, 'pixonaut_side_0_template', 'shirt', 'T-shirt med tryck');
		await expect(page.locator('#pixonaut_side_0_template')).not.toHaveValue('');
		await expect(warning).toBeHidden();
	}
});

test('a template-only product is sold in the sizes entered on the product screen', async ({
	connectedStore: page,
	fake,
	baseURL,
	theme,
}) => {
	test.skip(theme !== 'storefront', 'wp-admin does not depend on the theme');
	await usePlainPermalinks(page, baseURL);
	await page.goto('/wp-admin/post-new.php?post_type=product');
	await page.locator('#title').fill(`Template-only sign ${Date.now()}`);
	await page.locator('#_regular_price').fill('20');
	await openPixonautTab(page);
	const panel = productPanel(page);
	await panel.getByLabel('Personalise').check();
	await pick(page, 'pixonaut_side_0_template', 'shirt', 'T-shirt med tryck');
	const side = panel.locator('.pixonaut-side[data-index="0"]');
	// Without a blank every size is entered: there is no placement default.
	await expect(
		side.getByRole('button', { name: 'Add the print position’s size' }),
	).toBeHidden();
	await side.getByRole('button', { name: 'Remove', exact: true }).click();
	await publish(page);

	// No size: saved, but not sold, and the screen says why.
	await expect(page.locator('.pixonaut-notice')).toContainText(
		'The side has no print size, and without a blank there is no print position to take one from. Add a size in millimetres in the product’s Pixonaut tab, or choose a blank.',
	);

	// The menu shows one empty size to fill in.
	await openPixonautTab(page);
	await expect(side.locator('.pixonaut-size')).toHaveCount(1);
	await side.getByLabel('Width in millimetres').fill(`${MINI_TEE_RATIO.w}`);
	await side.getByLabel('Height in millimetres').fill(`${MINI_TEE_RATIO.h}`);
	await side.getByLabel('Label customers see').fill('Poster');
	// Its orders go to the printshop chosen here, the workspace's default until then.
	const printshop = panel.getByLabel('Printshop', { exact: true });
	await expect(printshop).toHaveValue('');
	await expect(printshop.locator('option')).toHaveText([
		'Workspace default (Fixture Print AB)',
		'Fixture Print AB',
		'Other Print AB',
	]);
	await printshop.selectOption({ label: 'Other Print AB' });
	await publish(page);

	await openPixonautTab(page);
	await expect(panel.locator('.pixonaut-product-invalid')).toHaveCount(0);
	await expect(page.locator('.pixonaut-notice')).toHaveCount(0);
	const checks = (await fake.requests()).filter(
		(r) => r.operation === 'POST /v1/templates/{id}/print-size',
	);
	expect(checks).toHaveLength(1);
	await expect(side.getByLabel('Width in millimetres')).toHaveValue(
		`${MINI_TEE_RATIO.w}`,
	);
	await expect(
		printshop.locator('option:checked'),
		'the choice is saved',
	).toHaveText('Other Print AB');
});
