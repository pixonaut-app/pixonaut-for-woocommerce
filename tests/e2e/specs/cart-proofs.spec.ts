import type { Locator, Page } from '@playwright/test';
import { expect, restUrl, settingsPath, signIn, test } from '../fixtures';
import { openCheckouts } from '../orders';
import { addPersonalised, connectFixtureStore } from '../personalised';

/** The plugin's proof route, as an `img` or link names it: under
 *  `/wp-json/` or, on plain permalinks, encoded in `rest_route`. */
const PROOF_ROUTE =
	/(\/wp-json\/|rest_route=%2F)pixonaut(\/|%2F)v1(\/|%2F)proof(\/|%2F)/;

/** Each cart's address on the tests site. */
let cartUrls: Record<'classic' | 'block' | 'mini', string>;

test.beforeEach(async ({ request, baseURL }) => {
	await connectFixtureStore(request, baseURL ?? '');
	const response = await request.post(restUrl('/pixonaut-e2e/v1/cart'));
	expect(response.ok(), 'the tests site has both carts').toBe(true);
	cartUrls = await response.json();
});

/** Expects an image to be the proof, served by the store and decoded. */
async function expectProof(image: Locator, fakeOrigin: string): Promise<void> {
	await expect(image).toHaveAttribute('src', PROOF_ROUTE);
	const src = (await image.getAttribute('src')) ?? '';
	expect(src.startsWith(fakeOrigin)).toBe(false);
	await expect
		.poll(() => image.evaluate((img: HTMLImageElement) => img.naturalWidth))
		.toBeGreaterThan(0);
}

test('the cart shows the proof in place of the product image', async ({
	page,
	request,
	theme,
	fakeBaseURL,
}) => {
	await addPersonalised(page, request, `Proof ${theme}`);
	const fakeOrigin = new URL(fakeBaseURL).origin;

	await page.goto(cartUrls.classic);
	const classicLine = page.locator('.woocommerce-cart-form .cart_item');
	await expectProof(
		classicLine.locator('img.pixonaut-proof-thumbnail'),
		fakeOrigin,
	);
	// No row per side: the line lists only the values the customer filled in.
	await expect(classicLine.locator('dl.variation a')).toHaveCount(0);
	await expect(classicLine.getByText('View proof')).toHaveCount(0);
	expect(await classicLine.innerHTML()).not.toContain(fakeOrigin);

	await page.goto(cartUrls.block);
	const blockLine = page.locator('.wc-block-cart-items__row').first();
	await expectProof(
		blockLine.locator('.wc-block-cart-item__image img'),
		fakeOrigin,
	);
	await expect(
		blockLine.locator('.wc-block-components-product-details a'),
	).toHaveCount(0);
	await expect(blockLine.getByText('View proof')).toHaveCount(0);
	expect(await blockLine.innerHTML()).not.toContain(fakeOrigin);
});

/** The setting that lists a line's design fields to the customer. */
const LIST_FIELDS = '[id="pixonaut_wc_settings[show_field_values]"]';

/** Turns the field list on or off on the settings page's Storefront tab. */
async function listFields(admin: Page, listed: boolean): Promise<void> {
	await admin.goto(settingsPath('storefront'));
	await admin.locator(LIST_FIELDS).setChecked(listed);
	await admin.getByRole('button', { name: 'Save changes' }).click();
	await expect(admin.getByText('Your settings have been saved.')).toBeVisible();
}

/** The fixture tee's text size, as its form shows it: the designer holds
 *  the fraction 0.0225…, which no line should show. The Cart block ends
 *  each detail but the last with ` / `; the Mini-Cart's markup keeps its
 *  template's line breaks. */
const TEXT_SIZE = /^\s*Storlek:\s*2\.3%\s*(\/\s*)?$/;

/** The fixture tee's text colour, a CMYK black outside its palette, by the
 *  hex its form's colour field heads it with. */
const TEXT_COLOUR = /^\s*Färg:\s*#1A1A18\s*(\/\s*)?$/;

/** Expects a line's details to list the design's fields in the words the
 *  form shows them in. */
async function expectFormWords(details: Locator): Promise<void> {
	await expect(details).toContainText('Slogan');
	await expect(details.locator('> *', { hasText: 'Storlek' })).toHaveText(
		TEXT_SIZE,
	);
	await expect(details.locator('> *', { hasText: 'Färg' })).toHaveText(
		TEXT_COLOUR,
	);
	// No raw fraction or CMYK channels.
	await expect(details).not.toContainText(/\d\.\d{5,}/);
	await expect(details).not.toContainText(/C\d+ M\d+ Y\d+ K\d+/);
}

test('a line lists its design fields, as the form showed them, only once the setting is on', async ({
	page,
	request,
	theme,
	browser,
	baseURL,
}) => {
	const checkoutUrls = await openCheckouts(request);
	await addPersonalised(page, request, `Fields ${theme}`);
	await page.goto(cartUrls.block);
	const line = page.locator('.wc-block-cart-items__row').first();
	await expect(line.locator('.wc-block-cart-item__image img')).toBeVisible();
	await expect(line.getByText('Slogan')).toHaveCount(0);

	const context = await browser.newContext({ baseURL });
	const admin = await context.newPage();
	await signIn(admin, baseURL ?? '');
	await listFields(admin, true);
	try {
		await page.reload();
		await expectFormWords(line.locator('.wc-block-components-product-details'));

		await page.goto(cartUrls.mini);
		await page.getByRole('main').locator('.wc-block-mini-cart__button').click();
		await expectFormWords(
			page
				.locator('.wc-block-mini-cart__drawer .wc-block-cart-items__row')
				.first()
				// The drawer also holds a hidden, empty list for a variation's
				// attributes.
				.locator('.wc-block-components-product-details:visible'),
		);

		await page.goto(checkoutUrls.block);
		await expectFormWords(
			page
				.locator('.wc-block-components-order-summary-item')
				.first()
				.locator('.wc-block-components-product-details'),
		);
	} finally {
		await listFields(admin, false);
		await context.close();
	}
});

test('the mini-cart shows the proof', async ({
	page,
	request,
	theme,
	fakeBaseURL,
}) => {
	await addPersonalised(page, request, `Proof ${theme}`);
	const fakeOrigin = new URL(fakeBaseURL).origin;

	await page.goto(cartUrls.mini);
	// The page's own Mini-Cart: on a block theme WooCommerce may hook another
	// into the header.
	await page.getByRole('main').locator('.wc-block-mini-cart__button').click();
	await expectProof(
		page.locator(
			'.wc-block-mini-cart__drawer .wc-block-cart-item__image img:visible',
		),
		fakeOrigin,
	);
	await expect(
		page.locator(
			'.wc-block-mini-cart__drawer .wc-block-components-product-details a',
		),
	).toHaveCount(0);

	if (theme === 'storefront') {
		// Storefront's own header cart lists the line on hover.
		await page.goto('/');
		await page.locator('.site-header-cart').hover();
		await expectProof(
			page.locator('.widget_shopping_cart img.pixonaut-proof-thumbnail'),
			fakeOrigin,
		);
	}
});
