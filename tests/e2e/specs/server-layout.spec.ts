import type { APIRequestContext, Page } from '@playwright/test';
import { FIXTURE } from '../../fake-pixonaut/src/fixture-ids';
import { expect, type FakePixonaut, restUrl, test } from '../fixtures';
import {
	addToCartButton,
	connectFixtureStore,
	createProduct,
	PERSONALISED,
} from '../personalised';

/** The fixture tee's photos, 1000 pixels square. */
const PHOTO_RATIO = '1000 / 1000';

/** The steps of mini-tee's form, and the fields they ask for, the motif's
 *  scale slider among them. */
const MINI_TEE_STEPS = ['Ladda upp motiv', 'Text'];
const MINI_TEE_FIELDS = [
	'Motiv',
	'Size',
	'Slogan',
	'Typsnitt',
	'Storlek',
	'Färg',
];

/** mini-tee's form laid out one step at a time. */
const IN_STEPS = { ...PERSONALISED, v: 3, formLayout: 'steps' };

/** A desktop's viewport. */
const DESKTOP = { width: 1280, height: 800 };

/** How far, in CSS pixels, a box may move or change size and still be
 *  where it was: the SDK rounds its own sizes. */
const SAME_BOX_PX = 2;

/** A phone's viewport, below the 768px breakpoint. */
const PHONE = { width: 390, height: 844 };

/** A form narrower than the 320px below which the designer's stepper
 *  drops its step names and shortens its counter. */
const NARROW_FORM_PX = 300;

/** How much of a phone's height the narrow layout gives the preview at most. */
const PHONE_PREVIEW_SHARE = 0.8;

/** The Pixonaut reads the server makes for the designer. */
const SERVER_READ_PATHS = [
	/\/v1\/templates\/[^/]+$/,
	/\/v1\/templates\/[^/]+\/constraints/,
	/\/v1\/blanks\/[^/]+\/mockups/,
	/\/v1\/blanks\/[^/]+\/variants/,
];

const FRONT_FORM = '#pixonaut-form-front [data-testid="pixonaut-form"]';

const FRONT_FRAME = '.pixonaut-preview__frame[data-pixonaut-side="front"]';

/** mini-tee on the tee's front and back, inline. */
const FRONT_AND_BACK = {
	...PERSONALISED,
	sides: [
		...PERSONALISED.sides,
		{
			key: 'back',
			label: 'Back',
			placement: { id: FIXTURE.placement.back, name: 'Back' },
			template: PERSONALISED.sides[0]?.template,
			printSizes: [],
		},
	],
};

/** The print sizes of a variable product whose variations each print at
 *  one of them, as a size choice in the add-to-cart form. */
const SIGN_SMALL = { widthMm: 350, heightMm: 500, label: '35 × 50 cm' };
const SIGN_LARGE = { widthMm: 500, heightMm: 700, label: '50 × 70 cm' };

/** The product offers both sizes, each variation one; the form opens on
 *  the large one. */
const SIGN = {
	...PERSONALISED,
	approvalMode: 'checkbox',
	sides: [{ ...PERSONALISED.sides[0], printSizes: [SIGN_SMALL, SIGN_LARGE] }],
};
const SIGN_PRICES = {
	[SIGN_SMALL.label]: '249.00',
	[SIGN_LARGE.label]: '349.00',
};
const SIGN_LARGE_PRICE = /349[.,]00/;
const SIGN_SMALL_PRICE = /249[.,]00/;

/** Read by the e2e support mu-plugin: the store's `attribute_options`
 *  setting for this browser context. */
const ATTRIBUTE_OPTIONS_COOKIE = 'pixonaut_e2e_attribute_options';

/** The accent a block theme defines, as the Pixonaut Shop theme does, which
 *  draws the step numbers as badges. */
const ACCENT = '#2f5bd3';

type Box = { x: number; y: number; width: number; height: number };

test.beforeEach(async ({ request, baseURL }) => {
	await connectFixtureStore(request, baseURL ?? '');
});

test.afterEach(async ({ request }) => {
	await request.delete(restUrl('/pixonaut-e2e/v1/connection'));
});

/** Holds requests matching `url` back until the returned function is
 *  called. */
async function holdBack(
	page: Page,
	url: Parameters<Page['route']>[0],
): Promise<() => void> {
	let release: () => void = () => undefined;
	const held = new Promise<void>((resolve) => {
		release = resolve;
	});
	await page.route(url, async (route) => {
		await held;
		await route.continue();
	});
	return release;
}

/** Matches the designer's `POST /v1/proofs` on the fake, whatever query
 *  the SDK adds (`?tz=`). */
function proofsOn(fake: FakePixonaut): (url: URL) => boolean {
	return (url) =>
		url.origin === new URL(fake.baseURL).origin &&
		url.pathname === '/v1/proofs';
}

/** Holds the SDK script back, so the page shows what the server printed
 *  alone until the returned function is called. */
function holdSdk(page: Page): Promise<() => void> {
	return holdBack(page, 'https://cdn.pixonaut.app/**');
}

/** Holds the SDK's wasm back, so the designer builds its form from the
 *  template but draws no preview until the returned function is called. */
function holdPreview(page: Page): Promise<() => void> {
	return holdBack(page, '**/pixonaut_wasm_bg.wasm');
}

async function box(page: Page, selector: string): Promise<Box> {
	const found = await page.locator(selector).boundingBox();
	expect(found, `${selector} is laid out`).not.toBeNull();
	return found ?? { x: 0, y: 0, width: 0, height: 0 };
}

/** Where the front form and each of its step headings and field labels
 *  named are on the page, by their words, whoever drew them. */
async function fieldPlaces(
	page: Page,
	names: string[] = [...MINI_TEE_STEPS, ...MINI_TEE_FIELDS],
): Promise<Record<string, Box>> {
	const places: Record<string, Box> = {};
	const scrolled = await page.evaluate(() => window.scrollY);
	for (const name of names) {
		// A page's step heading is numbered.
		const found = await page
			.locator('#pixonaut-form-front')
			.getByText(new RegExp(`^(\\d\\. )?${name}$`))
			.boundingBox();
		expect(found, `${name} is laid out`).not.toBeNull();
		places[name] = {
			...(found ?? { x: 0, y: 0, width: 0, height: 0 }),
			y: (found?.y ?? 0) + scrolled,
		};
	}
	const form = await box(page, '#pixonaut-form-front');
	places.form = { ...form, y: form.y + scrolled };
	return places;
}

/** Each place in `after` is where it was in `before`, but for its height:
 *  the designer's form takes the cart row in at its end. */
function expectSamePlaces(
	before: Record<string, Box>,
	after: Record<string, Box>,
): void {
	for (const [name, place] of Object.entries(before)) {
		for (const key of ['x', 'y', 'width'] as const) {
			expect(
				Math.abs((after[name]?.[key] ?? Number.NaN) - place[key]),
				`${name} ${key}`,
			).toBeLessThanOrEqual(SAME_BOX_PX);
		}
	}
}

async function expectSameBox(
	page: Page,
	selector: string,
	before: Box,
): Promise<void> {
	const after = await box(page, selector);
	for (const key of ['x', 'y', 'width', 'height'] as const) {
		expect(
			Math.abs(after[key] - before[key]),
			`${selector} ${key}`,
		).toBeLessThanOrEqual(SAME_BOX_PX);
	}
}

/** The statuses the fake answered an operation with, in order. */
async function statuses(
	fake: FakePixonaut,
	operation: string,
): Promise<number[]> {
	return (await fake.requests())
		.filter((r) => r.operation === operation)
		.map((r) => r.status);
}

test('the page prints the designer at its final layout before the SDK runs', async ({
	page,
	request,
	theme,
}) => {
	const permalink = await createProduct(
		request,
		`Server layout ${theme} ${Date.now()}`,
		PERSONALISED,
	);
	const release = await holdSdk(page);
	// The held SDK would hold the load event once the designer is in view.
	await page.goto(permalink, { waitUntil: 'domcontentloaded' });

	const frame = page.locator(FRONT_FRAME);
	await expect(frame).toHaveAttribute(
		'style',
		new RegExp(`--pixonaut-preview-ratio: ${PHOTO_RATIO}`),
	);
	await expect(frame.getByRole('status')).toBeAttached();
	await expect(
		page.locator('#pixonaut-form-front .pixonaut-placeholder__step'),
	).toHaveText(MINI_TEE_STEPS);
	await expect(
		page
			.locator(
				'#pixonaut-form-front [data-pixonaut-type] .pixonaut-placeholder__label',
			)
			.filter({ hasText: /\S/ }),
	).toHaveText(MINI_TEE_FIELDS);
	// The views strip is under the preview, in its column.
	const preview = await box(page, '#pixonaut-preview-front');
	const strip = await box(page, '#pixonaut-views-front');
	expect(strip.y).toBeGreaterThanOrEqual(preview.y + preview.height);
	expect(Math.abs(strip.x - preview.x)).toBeLessThanOrEqual(SAME_BOX_PX);
	const views = await page
		.locator('#pixonaut-views-front .pixonaut-views__view')
		.count();

	release();
	await expect(frame).toHaveClass(/is-drawn/);
	await expect(frame.getByRole('status')).toBeHidden();
	await expectSameBox(page, '#pixonaut-preview-front', preview);
	await expect(
		page.locator('#pixonaut-views-front [data-testid="pixonaut-view"]'),
	).toHaveCount(views);
	for (const step of MINI_TEE_STEPS) {
		await expect(
			page.locator('#pixonaut-form-front h3', { hasText: step }),
		).toBeVisible();
	}
});

for (const viewport of [DESKTOP, PHONE]) {
	test(`the printed form is the designer's, and nothing in it moves when it takes over, at ${viewport.width}px`, async ({
		page,
		request,
		theme,
	}) => {
		await page.setViewportSize(viewport);
		const permalink = await createProduct(
			request,
			`Server form ${theme} ${viewport.width} ${Date.now()}`,
			PERSONALISED,
		);
		const release = await holdSdk(page);
		await page.goto(permalink, { waitUntil: 'domcontentloaded' });

		const printed = page.locator(
			'#pixonaut-form-front .pixonaut-form__placeholder',
		);
		await expect(printed.getByText('Upload an image')).toBeVisible();
		await expect(printed.locator('textarea')).toHaveValue('Din text här');
		await expect(printed.getByText('12 / 40')).toBeVisible();
		await expect(printed.locator('.pixonaut-placeholder__tile')).toHaveCount(2);
		await expect(printed.locator('.pixonaut-placeholder__thumb')).toHaveCount(
			2,
		);
		await expect(printed.locator('.pixonaut-placeholder__swatch')).toHaveCount(
			1,
		);
		const before = await fieldPlaces(page);

		release();
		await expect(page.locator(FRONT_FORM)).toBeVisible();
		expectSamePlaces(before, await fieldPlaces(page));
	});
}

for (const viewport of [DESKTOP, PHONE]) {
	test(`the designer's form takes the printed one's place before the preview is drawn, and nothing moves, at ${viewport.width}px`, async ({
		page,
		request,
		theme,
	}) => {
		await page.setViewportSize(viewport);
		const permalink = await createProduct(
			request,
			`Server form first ${theme} ${viewport.width} ${Date.now()}`,
			PERSONALISED,
		);
		const releaseSdk = await holdSdk(page);
		// Registered last, so it handles the wasm before the SDK's hold does.
		const releasePreview = await holdPreview(page);
		await page.goto(permalink, { waitUntil: 'domcontentloaded' });
		const frame = page.locator(FRONT_FRAME);
		await expect(
			page.locator('#pixonaut-form-front .pixonaut-form__placeholder'),
		).toBeVisible();
		const printed = await fieldPlaces(page);
		const preview = await box(page, '#pixonaut-preview-front');

		releaseSdk();
		await expect(page.locator(FRONT_FORM)).toBeVisible();
		await expect(
			page.locator('#pixonaut-form-front .pixonaut-form__placeholder'),
		).toHaveCount(0);
		await expect(frame.getByRole('status')).toBeVisible();
		await expect(frame).not.toHaveClass(/is-drawn/);
		expectSamePlaces(printed, await fieldPlaces(page));
		await expectSameBox(page, '#pixonaut-preview-front', preview);

		releasePreview();
		await expect(frame).toHaveClass(/is-drawn/);
		await expectSameBox(page, '#pixonaut-preview-front', preview);
		expectSamePlaces(printed, await fieldPlaces(page));
	});
}

test('a form in steps is printed on its first step, where the designer draws it', async ({
	page,
	request,
	theme,
}) => {
	const permalink = await createProduct(
		request,
		`Server form steps ${theme} ${Date.now()}`,
		IN_STEPS,
	);
	const release = await holdSdk(page);
	await page.goto(permalink, { waitUntil: 'domcontentloaded' });
	const printed = page.locator(
		'#pixonaut-form-front .pixonaut-form__placeholder',
	);
	await expect(printed.getByText('Step 1 of 2')).toBeVisible();
	await expect(printed.getByText('Next: Text')).toBeVisible();
	const names = ['Step 1 of 2', 'Motiv', 'Size'];
	const before = await fieldPlaces(page, names);

	release();
	await expect(page.locator(FRONT_FORM)).toBeVisible();
	expectSamePlaces(before, await fieldPlaces(page, names));
});

test('a narrow form in steps is printed with the short counter, as the designer draws it', async ({
	page,
	request,
	theme,
}) => {
	const permalink = await createProduct(
		request,
		`Server form narrow steps ${theme} ${Date.now()}`,
		IN_STEPS,
	);
	const release = await holdSdk(page);
	await page.goto(permalink, { waitUntil: 'domcontentloaded' });
	await page.addStyleTag({
		content: `#pixonaut-form-front { width: ${NARROW_FORM_PX}px; }`,
	});
	const printed = page.locator(
		'#pixonaut-form-front .pixonaut-form__placeholder',
	);
	await expect(printed.getByText('1 of 2', { exact: true })).toBeVisible();
	await expect(printed.getByText('Step 1 of 2')).toBeHidden();
	await expect(
		printed.locator('.pixonaut-placeholder__segment-name').first(),
	).toBeHidden();
	const names = ['1 of 2', 'Motiv', 'Size'];
	const before = await fieldPlaces(page, names);

	release();
	await expect(page.locator(FRONT_FORM)).toBeVisible();
	expectSamePlaces(before, await fieldPlaces(page, names));
});

test('the views strip follows the side tab', async ({
	page,
	request,
	theme,
}) => {
	const permalink = await createProduct(
		request,
		`Server layout sides ${theme} ${Date.now()}`,
		FRONT_AND_BACK,
	);
	await page.goto(permalink);

	await expect(page.locator('#pixonaut-views-front')).toBeVisible();
	await expect(page.locator('#pixonaut-views-back')).toBeHidden();
	await page.getByRole('tab', { name: 'Back' }).click();
	await expect(page.locator('#pixonaut-views-back')).toBeVisible();
	await expect(page.locator('#pixonaut-views-front')).toBeHidden();
});

test('the narrow layout keeps the preview the size it was printed at', async ({
	page,
	request,
	theme,
}) => {
	await page.setViewportSize(PHONE);
	const permalink = await createProduct(
		request,
		`Server layout phone ${theme} ${Date.now()}`,
		PERSONALISED,
	);
	const release = await holdSdk(page);
	// The held SDK would hold the load event once the designer is in view.
	await page.goto(permalink, { waitUntil: 'domcontentloaded' });
	const printed = await box(page, '#pixonaut-preview-front');
	expect(printed.height).toBeLessThanOrEqual(
		PHONE.height * PHONE_PREVIEW_SHARE + SAME_BOX_PX,
	);

	release();
	await expect(page.locator(FRONT_FRAME)).toHaveClass(/is-drawn/);
	await expect(page.locator(FRONT_FORM)).toBeVisible();
	await expectSameBox(page, '#pixonaut-preview-front', printed);
});

test('Add to cart shows a spinner and adds the product once however often it is clicked', async ({
	page,
	request,
	fake,
	theme,
}) => {
	const permalink = await createProduct(
		request,
		`Server layout busy ${theme} ${Date.now()}`,
		PERSONALISED,
	);
	const release = await holdBack(page, proofsOn(fake));
	await page.goto(permalink);
	await expect(page.locator(FRONT_FORM)).toBeVisible();

	const button = addToCartButton(page);
	await button.click();
	await expect(button).toHaveClass(/pixonaut-busy/);
	await expect(button).toHaveAttribute('aria-busy', 'true');
	await button.dispatchEvent('click');
	const added = page.waitForRequest(
		(r) => r.method() === 'POST' && r.resourceType() === 'document',
	);
	release();
	await (await added).response();
	await expect(page.getByText(/has been added to your cart/)).toBeVisible();

	expect(await statuses(fake, 'POST /v1/proofs')).toEqual([201]);
	expect(await statuses(fake, 'POST /v1/designs')).toEqual([201]);
});

test('Add to cart is free again once the approval fails', async ({
	page,
	request,
	fake,
	theme,
}) => {
	const permalink = await createProduct(
		request,
		`Server layout refused ${theme} ${Date.now()}`,
		PERSONALISED,
	);
	await page.route(proofsOn(fake), (route) => route.abort());
	await page.goto(permalink);
	await expect(page.locator(FRONT_FORM)).toBeVisible();

	const button = addToCartButton(page);
	await button.click();
	await expect(page.locator('.pixonaut-form__error')).toBeVisible();
	await expect(button).not.toHaveClass(/pixonaut-busy/);
	await expect(button).not.toHaveAttribute('aria-busy', 'true');
	await expect(button).toBeEnabled();
});

test('the page does not move while the designer starts', async ({
	page,
	request,
	theme,
}) => {
	const permalink = await createProduct(
		request,
		`Server layout shift ${theme} ${Date.now()}`,
		PERSONALISED,
	);
	const release = await holdSdk(page);
	await page.goto(permalink, { waitUntil: 'domcontentloaded' });
	// The theme's own fonts load first: a menu other specs filled with pages
	// can wrap when its icons do, which is not the designer moving the page.
	await page.evaluate(() => document.fonts.ready);
	await page.evaluate(() => {
		const shifts = { total: 0 };
		Object.assign(window, { pixonautShifts: shifts });
		new PerformanceObserver((list) => {
			for (const entry of list.getEntries() as unknown as {
				value: number;
				hadRecentInput: boolean;
			}[]) {
				if (!entry.hadRecentInput) {
					shifts.total += entry.value;
				}
			}
		}).observe({ type: 'layout-shift' });
	});
	release();
	await expect(page.locator(FRONT_FRAME)).toHaveClass(/is-drawn/);
	await expect(page.locator(FRONT_FORM)).toBeVisible();

	const shift = await page.evaluate(
		() =>
			(window as unknown as { pixonautShifts: { total: number } })
				.pixonautShifts.total,
	);
	expect(shift).toBe(0);
});

test('the browser makes none of the reads the server made', async ({
	page,
	request,
	fake,
	theme,
}) => {
	const permalink = await createProduct(
		request,
		`Server reads ${theme} ${Date.now()}`,
		PERSONALISED,
	);
	const browserReads: string[] = [];
	page.on('request', (r) => {
		const { pathname } = new URL(r.url());
		if (
			r.url().startsWith(fake.baseURL) &&
			SERVER_READ_PATHS.some((path) => path.test(pathname))
		) {
			browserReads.push(pathname);
		}
	});
	await page.goto(permalink);
	await expect(page.locator(FRONT_FRAME)).toHaveClass(/is-drawn/);

	expect(browserReads).toEqual([]);
});

/** A variable product whose every variation prints at its own size, priced
 *  apart, opening on the large one; its permalink. */
async function createSign(
	request: APIRequestContext,
	name: string,
): Promise<string> {
	const sized = (size: typeof SIGN_SMALL) => ({
		...SIGN,
		sides: [{ ...SIGN.sides[0], printSizes: [size] }],
	});
	const response = await request.post(restUrl('/pixonaut-e2e/v1/products'), {
		data: {
			name,
			pixonaut: SIGN,
			variations: [SIGN_SMALL.label, SIGN_LARGE.label],
			default: SIGN_LARGE.label,
			prices: SIGN_PRICES,
			overrides: {
				[SIGN_SMALL.label]: sized(SIGN_SMALL),
				[SIGN_LARGE.label]: sized(SIGN_LARGE),
			},
			variant: FIXTURE.variant.teeWhiteS,
		},
	});
	expect(response.status()).toBe(201);
	return (await response.json()).permalink;
}

/** The summary price outside the add-to-cart form, which the script writes
 *  the chosen variation's price in, in Storefront and in Twenty
 *  Twenty-Five. */
function summaryPrice(page: Page) {
	return page
		.locator('.summary .price, .wc-block-components-product-price')
		.and(page.locator(':not(form *):not(.products *)'))
		.first();
}

/** Where the size choice, the approval box, the quantity and Add to cart
 *  are on the page, whoever drew them, besides the form's steps and
 *  fields. */
async function signPlaces(
	page: Page,
	swatches: boolean,
): Promise<Record<string, Box>> {
	// A step's label sits beside its badge, which the printed heading
	// draws as part of its text: the headings are measured whole.
	const places = await fieldPlaces(page, MINI_TEE_FIELDS);
	const scrolled = await page.evaluate(() => window.scrollY);
	const headings = page.locator(
		'#pixonaut-form-front :is(.pixonaut-placeholder__step, h3.pixonaut-step)',
	);
	await expect(headings).toHaveCount(MINI_TEE_STEPS.length);
	for (const [index, step] of MINI_TEE_STEPS.entries()) {
		const found = await headings.nth(index).boundingBox();
		expect(found, `${step} is laid out`).not.toBeNull();
		places[step] = {
			...(found ?? { x: 0, y: 0, width: 0, height: 0 }),
			y: (found?.y ?? 0) + scrolled,
		};
	}
	const parts: Record<string, string> = {
		size: swatches
			? '.pixonaut-swatches'
			: ':is(select[name="attribute_size"], .wp-block-woocommerce-add-to-cart-with-options-variation-selector-attribute [role="radiogroup"])',
		approval: '.pixonaut-approval',
		quantity: 'form.cart input.qty',
		button: 'form.cart [type="submit"]',
	};
	for (const [name, selector] of Object.entries(parts)) {
		const found = await box(page, `${selector} >> visible=true`);
		places[name] = { ...found, y: found.y + scrolled };
	}
	return places;
}

for (const { viewport, swatches } of [
	{ viewport: DESKTOP, swatches: false },
	{ viewport: DESKTOP, swatches: true },
	{ viewport: PHONE, swatches: true },
]) {
	test(`a product whose variations set the print size is printed as the designer draws it, at its opening variation's price, and nothing moves when it takes over, at ${viewport.width}px${swatches ? ' with swatches' : ''}`, async ({
		page,
		request,
		theme,
		baseURL,
	}) => {
		await page.setViewportSize(viewport);
		if (swatches) {
			await page.context().addCookies([
				{
					name: ATTRIBUTE_OPTIONS_COOKIE,
					value: 'swatches',
					url: baseURL ?? '',
				},
			]);
		}
		// The preset on `:root` before the storefront script reads it.
		await page.addInitScript((accent) => {
			document.addEventListener('readystatechange', () => {
				if (document.readyState !== 'interactive') {
					return;
				}
				const style = document.createElement('style');
				style.textContent = `:root { --wp--preset--color--accent: ${accent}; }`;
				document.head.append(style);
			});
		}, ACCENT);
		const permalink = await createSign(
			request,
			`Server sign ${theme} ${viewport.width} ${swatches} ${Date.now()}`,
		);
		// The page as the server prints it shows the opening variation's price.
		const printedPage = await (await page.request.get(permalink)).text();
		const summary = printedPage.match(
			/class="(?:price|wc-block-components-product-price[^"]*)"[^>]*>([\s\S]*?)<\/(?:p|div)>/,
		)?.[1];
		expect(summary).toMatch(SIGN_LARGE_PRICE);
		expect(summary).not.toMatch(SIGN_SMALL_PRICE);
		// As are the swatches the script draws, until it does.
		expect(printedPage.includes('pixonaut-swatches--printed')).toBe(swatches);

		const release = await holdSdk(page);
		await page.goto(permalink, { waitUntil: 'domcontentloaded' });
		const printed = page.locator(
			'#pixonaut-form-front .pixonaut-form__placeholder',
		);
		await expect(printed).toBeVisible();
		await expect(printed.getByText('Print size')).toHaveCount(0);
		await expect(
			printed.locator('.pixonaut-placeholder__step').first(),
		).toHaveCSS('display', 'flex');
		await expect(page.locator('input[name="variation_id"]')).not.toHaveValue(
			/^0?$/,
		);
		await expect(
			page.locator('.pixonaut-swatches >> visible=true'),
		).toHaveCount(swatches ? 1 : 0);
		await page.evaluate(() => document.fonts.ready);
		const form = page.locator('form.cart');
		await expect(
			form.getByText(SIGN_LARGE_PRICE).filter({ visible: true }),
		).toHaveCount(0);
		await expect(summaryPrice(page)).toContainText(SIGN_LARGE_PRICE);
		const before = await signPlaces(page, swatches);

		release();
		await expect(page.locator(FRONT_FORM)).toBeVisible();
		await expect(printed).toHaveCount(0);
		await expect(
			page.locator(FRONT_FORM).locator('.pixonaut-step'),
		).toHaveCount(MINI_TEE_STEPS.length);
		if (swatches) {
			await expect(
				page.locator('.pixonaut-swatches:not(.pixonaut-swatches--printed)'),
			).toBeVisible();
		}
		await expect(
			form.getByText(SIGN_LARGE_PRICE).filter({ visible: true }),
		).toHaveCount(0);
		await expect(summaryPrice(page)).toContainText(SIGN_LARGE_PRICE);
		await expect(summaryPrice(page)).not.toContainText(SIGN_SMALL_PRICE);
		const after = await signPlaces(page, swatches);
		expectSamePlaces(before, after);
	});
}

/** Holds the storefront script back, so the page shows its first paint: what
 *  the server printed and the script printed with it, the deferred ones not
 *  yet run. */
function holdStorefront(page: Page): Promise<() => void> {
	return holdBack(page, '**/assets/build/storefront.js*');
}

/** The add-to-cart form's option fields, classic or block. */
const OPTION_FIELDS =
	'#pixonaut-form-front :is(table.variations, .wp-block-woocommerce-add-to-cart-with-options-variation-selector)';

/** An attribute's field where a side's groups place it. */
const PLACED_ATTRIBUTE =
	'#pixonaut-form-front :is(table.pixonaut-attribute, .wp-block-woocommerce-add-to-cart-with-options-variation-selector-attribute)';

/** mini-tee with its size placed, in a group of its own words, between its
 *  two groups' fields; `layout` lays it out. */
function arrangedTee(formLayout: 'page' | 'steps') {
	return {
		...PERSONALISED,
		v: 6,
		formLayout,
		sides: PERSONALISED.sides.map((side) => ({
			...side,
			formGroups: {
				groups: [
					{
						id: 'text-front',
						title: 'Your words',
						items: [
							{ attribute: 'size' },
							{ field: 'slogan:content', label: 'Name on the back' },
						],
					},
				],
			},
		})),
	};
}

/** Each form the printed form has to match, with what is measured in it: its
 *  words, and the parts of the add-to-cart form standing in it. */
const FORMS = [
	{
		name: 'arranged groups on a page',
		config: arrangedTee('page'),
		words: ['Your words', 'Name on the back', 'Motiv'],
		parts: [PLACED_ATTRIBUTE],
		cartShown: true,
	},
	{
		name: 'arranged groups in steps',
		config: arrangedTee('steps'),
		words: ['Name on the back', 'Next: Ladda upp motiv'],
		parts: [PLACED_ATTRIBUTE],
		cartShown: false,
	},
	{
		name: 'a form in steps',
		config: IN_STEPS,
		words: ['Next: Ladda upp motiv'],
		parts: [OPTION_FIELDS],
		cartShown: false,
	},
	{
		name: 'a form keeping its size picker',
		config: {
			...PERSONALISED,
			sides: [
				{ ...PERSONALISED.sides[0], printSizes: [SIGN_SMALL, SIGN_LARGE] },
			],
		},
		words: ['Print size', 'Motiv', 'Slogan'],
		parts: [OPTION_FIELDS],
		cartShown: true,
	},
	{
		name: 'a form on a page',
		config: PERSONALISED,
		words: ['Motiv', 'Slogan'],
		parts: [OPTION_FIELDS],
		cartShown: true,
	},
];

/** Where the words and parts are on the page, whoever drew them. */
async function formPlaces(
	page: Page,
	words: string[],
	parts: string[],
	cartShown: boolean,
): Promise<Record<string, Box>> {
	const places = await fieldPlaces(page, words);
	const scrolled = await page.evaluate(() => window.scrollY);
	for (const part of parts) {
		const found = await box(page, part);
		places[part] = { ...found, y: found.y + scrolled };
	}
	if (cartShown) {
		const button = await addToCartButton(page).boundingBox();
		expect(button, 'Add to cart is laid out').not.toBeNull();
		places.button = {
			...(button ?? { x: 0, y: 0, width: 0, height: 0 }),
			y: (button?.y ?? 0) + scrolled,
		};
	}
	// A theme's own deferred script can change its header's height (Storefront
	// shows its menu), which moves the whole page and says nothing of the form.
	const header = await box(page, 'header');
	const headerBottom = header.y + scrolled + header.height;
	return Object.fromEntries(
		Object.entries(places).map(([name, place]) => [
			name,
			{ ...place, y: place.y - headerBottom },
		]),
	);
}

/** The opening variation's price, and the other's. */
const TEE_PRICE = /249[.,]00/;
const DARK_PRICE = /349[.,]00/;

/** Picks a size in the add-to-cart form's own field, however it is drawn. */
async function chooseSize(page: Page, size: string): Promise<void> {
	const form = page.locator('#pixonaut-form-front');
	const swatch = form.locator(
		'.pixonaut-swatches:not(.pixonaut-swatches--printed) .pixonaut-swatches__swatch',
		{ hasText: size },
	);
	if ((await swatch.count()) > 0) {
		await swatch.click();
		return;
	}
	const select = form.locator('select[name="attribute_size"]');
	if ((await select.count()) > 0) {
		await select.selectOption(size);
		return;
	}
	await form.getByRole('radio', { name: size }).click();
}

for (const { viewport, swatches } of [
	{ viewport: DESKTOP, swatches: false },
	{ viewport: PHONE, swatches: false },
	{ viewport: DESKTOP, swatches: true },
	{ viewport: PHONE, swatches: true },
]) {
	for (const form of FORMS) {
		test(`${form.name} stands where the designer draws it from the first paint, at ${viewport.width}px${swatches ? ' with swatches' : ''}`, async ({
			page,
			request,
			theme,
			baseURL,
		}, testInfo) => {
			await page.setViewportSize(viewport);
			if (swatches) {
				await page.context().addCookies([
					{
						name: ATTRIBUTE_OPTIONS_COOKIE,
						value: 'swatches',
						url: baseURL ?? '',
					},
				]);
			}
			const response = await request.post(
				restUrl('/pixonaut-e2e/v1/products'),
				{
					data: {
						name: `Server form ${form.name} ${theme} ${viewport.width} ${Date.now()}`,
						pixonaut: form.config,
						variations: ['Tee', 'Dark'],
						default: 'Tee',
						prices: { Tee: '249.00', Dark: '349.00' },
						variant: FIXTURE.variant.teeWhiteS,
						variants: { Dark: FIXTURE.variant.teeBlackS },
					},
				},
			);
			expect(response.status()).toBe(201);
			const releaseSdk = await holdSdk(page);
			const releaseScript = await holdStorefront(page);
			await page.goto((await response.json()).permalink, {
				waitUntil: 'commit',
			});
			const printed = page.locator(
				'#pixonaut-form-front .pixonaut-form__placeholder',
			);
			await expect(printed).toBeVisible();
			const submit = page.locator(
				'form:is(.cart, [data-wp-interactive]) [type="submit"]',
			);
			await expect(submit).toBeAttached();
			const firstPaint = await formPlaces(
				page,
				form.words,
				form.parts,
				form.cartShown,
			);
			if (!form.cartShown) {
				await expect(submit).toBeHidden();
			}
			await expect(summaryPrice(page)).toContainText(TEE_PRICE);
			await expect(summaryPrice(page)).not.toContainText(DARK_PRICE);
			await expect(
				page.locator('.pixonaut-swatches--printed').filter({ visible: true }),
			).toHaveCount(swatches ? 1 : 0);
			await page.screenshot({
				path: testInfo.outputPath('first-paint.png'),
				fullPage: true,
			});

			releaseScript();
			await expect(page.locator(FRONT_FRAME)).toBeAttached();
			await page.waitForLoadState('domcontentloaded');
			expectSamePlaces(
				firstPaint,
				await formPlaces(page, form.words, form.parts, form.cartShown),
			);

			// The live controls work before the designer loads.
			await chooseSize(page, 'Dark');
			await expect(summaryPrice(page)).toContainText(DARK_PRICE);
			await chooseSize(page, 'Tee');
			await expect(summaryPrice(page)).toContainText(TEE_PRICE);

			releaseSdk();
			await expect(page.locator(FRONT_FORM)).toBeVisible();
			await expect(printed).toHaveCount(0);
			expectSamePlaces(
				firstPaint,
				await formPlaces(page, form.words, form.parts, form.cartShown),
			);
			await expect(summaryPrice(page)).toContainText(TEE_PRICE);
			await expect(summaryPrice(page)).not.toContainText(DARK_PRICE);
			await page.screenshot({
				path: testInfo.outputPath('taken-over.png'),
				fullPage: true,
			});
		});
	}
}
