import type { APIRequestContext, Page } from '@playwright/test';
import { expect, restUrl, test } from '../fixtures';
import { openCheckouts, pay } from '../orders';
import {
	connectFixtureStore,
	createProduct,
	PERSONALISED,
} from '../personalised';

// A shop's theme and page builder under the plugin, one project per
// combination (tests/compat/combinations.json). Runs in the Nightly's
// compatibility job, not in ci:local.

const VIEWPORTS = {
	desktop: { width: 1280, height: 800 },
	phone: { width: 390, height: 844 },
};

/** How far, in CSS pixels, two boxes may overlap and still sit apart: the
 *  SDK and the themes round their own sizes. */
const ROUNDING_PX = 2;

const FORM = '[data-testid="pixonaut-form"]';

/** What each page builder wraps its rendered layout in. A page falls back to
 *  the shortcode in its content when the builder never renders, so a product
 *  form inside this is what shows the builder printed it. */
const BUILDER_WRAPPERS: Record<string, string> = {
	elementor: '.elementor-widget-shortcode',
	'beaver-builder-lite-version': '.fl-builder-content',
	'siteorigin-panels': '.panel-layout',
};

/** What the customer writes on the fixture tee's text layer. */
const SLOGAN = 'Printed under this theme';

/** What a browser logs when the test's own context blocks an outside host,
 *  such as a theme's web fonts, or when a resource answers an error status. */
const NOT_A_SCRIPT_ERROR = /Failed to load resource|ERR_BLOCKED_BY_CLIENT/;

/** Scrolls to the preview, as a customer does: the designer mounts once it
 *  comes into view, and a theme's header can push it below the fold. */
async function bringDesignerIntoView(page: Page): Promise<void> {
	await page.locator('.pixonaut-preview').first().scrollIntoViewIfNeeded();
}

/** Collects the page's script errors. */
function scriptErrors(page: Page): string[] {
	const errors: string[] = [];
	page.on('pageerror', (error) => errors.push(error.message));
	page.on('console', (message) => {
		if (
			message.type() === 'error' &&
			!NOT_A_SCRIPT_ERROR.test(message.text())
		) {
			errors.push(message.text());
		}
	});
	return errors;
}

/** How long a theme's own add to cart may take to put the line in the cart. */
const LINE_IN_CART_MS = 20_000;

/**
 * Personalises the product at `url` and adds it to the cart, then finds the
 * design on the cart line. Themes finish add to cart their own way: Blocksy,
 * for one, posts it in the background and shows no WooCommerce notice. With a
 * `wrapper`, the product's form must be inside it.
 */
async function designIntoCart(
	page: Page,
	request: APIRequestContext,
	url: string,
	wrapper?: string,
): Promise<void> {
	await page.goto(url);
	if (wrapper) {
		await expect(page.locator(wrapper).locator('form.cart')).toBeVisible();
	}
	await bringDesignerIntoView(page);
	await expect(page.locator('.pixonaut-preview *').first()).toBeVisible();
	await page.getByRole('textbox', { name: /Slogan/ }).fill(SLOGAN);
	const proof = page.waitForRequest(
		(r) => r.method() === 'POST' && new URL(r.url()).pathname === '/v1/proofs',
	);
	await page
		.locator('form.cart')
		.getByRole('button', { name: /add to cart/i })
		.click();
	expect((await proof).postData()).toContain(SLOGAN);

	const carts = await request.post(restUrl('/pixonaut-e2e/v1/cart'));
	expect(carts.ok(), 'the tests site has its carts').toBe(true);
	const { classic } = await carts.json();
	await expect(async () => {
		// A new page in the same browser: the theme's request may still be running on this one.
		const cart = await page.context().newPage();
		await cart.goto(classic);
		await expect(
			cart.locator(
				'.woocommerce-cart-form .cart_item img.pixonaut-proof-thumbnail',
			),
		).toBeVisible({ timeout: LINE_IN_CART_MS / 4 });
		await cart.close();
	}).toPass({ timeout: LINE_IN_CART_MS });
}

test.beforeEach(async ({ request, baseURL }) => {
	await connectFixtureStore(request, baseURL ?? '');
	await request.delete(restUrl('/pixonaut-e2e/v1/php-errors'));
});

test.afterEach(async ({ request }) => {
	const errors = await request.get(restUrl('/pixonaut-e2e/v1/php-errors'));
	await request.delete(restUrl('/pixonaut-e2e/v1/connection'));
	expect(await errors.json(), 'PHP warnings and notices').toEqual([]);
});

for (const [device, viewport] of Object.entries(VIEWPORTS)) {
	test(`the form renders and keeps its layout on a ${device}`, async ({
		page,
		request,
		theme,
	}, testInfo) => {
		const errors = scriptErrors(page);
		await page.setViewportSize(viewport);
		await page.goto(
			await createProduct(
				request,
				`Compatibility ${theme} ${device} ${Date.now()}`,
				PERSONALISED,
			),
		);
		await bringDesignerIntoView(page);
		const form = page.locator(FORM).first();
		await expect(form).toBeVisible();
		await page.evaluate(() => document.fonts.ready);
		await testInfo.attach(`${testInfo.project.name}-${device}`, {
			body: await page.screenshot(),
			contentType: 'image/png',
		});

		const widths = await page.evaluate(() => ({
			page: document.documentElement.scrollWidth,
			screen: document.documentElement.clientWidth,
		}));
		expect(
			widths.page,
			'the page is no wider than the screen',
		).toBeLessThanOrEqual(widths.screen);

		const previewBox = await page
			.locator('#pixonaut-preview-front')
			.boundingBox();
		const formBox = await form.boundingBox();
		expect(previewBox, 'the preview is laid out').not.toBeNull();
		expect(formBox, 'the form is laid out').not.toBeNull();
		if (previewBox && formBox) {
			const apart =
				formBox.x >= previewBox.x + previewBox.width - ROUNDING_PX ||
				formBox.y >= previewBox.y + previewBox.height - ROUNDING_PX;
			expect(apart, 'the form clears the preview').toBe(true);
		}
		expect(errors, 'script errors').toEqual([]);
	});
}

test('a design goes through the cart and the checkout', async ({
	page,
	request,
	theme,
}) => {
	test.slow();
	const errors = scriptErrors(page);
	const checkouts = await openCheckouts(request);

	await designIntoCart(
		page,
		request,
		await createProduct(
			request,
			`Compatibility ${theme} cart ${Date.now()}`,
			PERSONALISED,
		),
	);

	await pay(page, 'classic', checkouts);
	expect(errors, 'script errors').toEqual([]);
});

test('a builder page holding [product_page] sells the design', async ({
	page,
	request,
	theme,
	builder,
}) => {
	test.skip(!builder, 'This combination has no page builder.');
	test.slow();
	const errors = scriptErrors(page);
	const product = await request.post(restUrl('/pixonaut-e2e/v1/products'), {
		data: {
			name: `Compatibility ${theme} ${builder} ${Date.now()}`,
			pixonaut: PERSONALISED,
		},
	});
	expect(product.status()).toBe(201);
	const made = await request.post(restUrl('/pixonaut-e2e/v1/builder-pages'), {
		data: { builder, product: (await product.json()).id },
	});
	expect(made.status(), 'the builder page is made').toBe(201);
	const { id, permalink } = await made.json();

	try {
		const wrapper = BUILDER_WRAPPERS[builder];
		expect(wrapper, `${builder} has a known wrapper`).toBeTruthy();
		await designIntoCart(page, request, permalink, wrapper);
		expect(errors, 'script errors').toEqual([]);
	} finally {
		await request.delete(
			`${restUrl('/pixonaut-e2e/v1/builder-pages')}&id=${id}`,
		);
	}
});
