import type { APIRequestContext, Page } from '@playwright/test';
import { FIXTURE } from '../../fake-pixonaut/src/fixture-ids';
import { expect, restUrl, test } from '../fixtures';
import { statuses } from '../orders';
import {
	addToCartButton,
	connectFixtureStore,
	PERSONALISED,
} from '../personalised';

/** A product personalised on the Personalise button layout. */
const BUTTON_TEE = { ...PERSONALISED, layout: 'button' };

/** `Tee` inherits the product's configuration; `Plain` is bought without a design. */
const OVERRIDES = { Plain: { ...BUTTON_TEE, personalise: false } };

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
			pixonaut: BUTTON_TEE,
			variations: ['Tee', 'Plain'],
			overrides: OVERRIDES,
		},
	});
	expect(response.status()).toBe(201);
	return (await response.json()).permalink;
}

function personaliseButton(page: Page) {
	return page
		.locator('form.cart, form[data-wp-interactive]')
		.getByRole('button', { name: 'Personalise' });
}

/** Chooses the variation, and waits until WooCommerce takes an add to cart
 *  for it: the classic form enables its button 300 ms after it found the
 *  variation, and refuses a click before then. */
async function chooseSize(page: Page, size: string): Promise<void> {
	await page.locator('select[name="attribute_size"]').selectOption(size);
	await expect(addToCartButton(page)).not.toHaveClass(/\bdisabled\b/);
}

async function expectAdded(page: Page): Promise<void> {
	await expect(page.getByText(/has been added to your cart/)).toBeVisible();
}

test('the Personalise button gives way to add to cart on a variation that is not personalised', async ({
	page,
	request,
	fake,
	theme,
}) => {
	test.slow();
	const permalink = await createProduct(
		request,
		`Button variations ${theme} ${Date.now()}`,
	);
	await page.goto(permalink);

	await chooseSize(page, 'Plain');
	await expect(personaliseButton(page)).toBeHidden();
	await addToCartButton(page).click();
	await expectAdded(page);
	expect(await statuses(fake, 'POST /v1/designs')).toEqual([]);

	await page.goto(permalink);
	await chooseSize(page, 'Plain');
	await chooseSize(page, 'Tee');
	await expect(addToCartButton(page)).toBeHidden();
	await personaliseButton(page).click();
	await page.waitForURL((url) => url.origin === new URL(fake.baseURL).origin);
	await page.getByRole('link', { name: /^Approve and / }).click();
	await expect(page.getByText(/This is your design/)).toBeVisible();
	await expect(page.locator('input[name="variation_id"]')).not.toHaveValue('0');
	await expect(addToCartButton(page)).not.toHaveClass(/\bdisabled\b/);
	await addToCartButton(page).click();
	await expectAdded(page);
	expect(await statuses(fake, 'POST /v1/designs')).toEqual([201]);
});

test('Enter in the quantity field adds a variation that is not personalised', async ({
	page,
	request,
	fake,
	theme,
}) => {
	test.slow();
	const permalink = await createProduct(
		request,
		`Button variations enter ${theme} ${Date.now()}`,
	);
	await page.goto(permalink);

	await chooseSize(page, 'Plain');
	await page.locator('input.qty').press('Enter');
	await expectAdded(page);
	expect(await statuses(fake, 'POST /v1/designs')).toEqual([]);
});

/** mini-poster's shape at two print sizes. */
const SMALL_SIGN = { widthMm: 500, heightMm: 350, label: 'Small' };
const LARGE_SIGN = { widthMm: 1000, heightMm: 700, label: 'Large' };

/** Each size's price, told apart by its whole units. */
const SIGN_PRICES = { Small: '23.00', Large: '47.00' };
const SMALL_PRICE = /23[.,]00/;
const LARGE_PRICE = /47[.,]00/;

/** The configuration version `layout: null` and no blank are stored at. */
const SIGN_CONFIG_VERSION = 11;

/** mini-poster on no blank at `sizes`; a variation's own setup names no
 *  layout, and so follows its product's. */
function sign(layout: string | null, sizes = [SMALL_SIGN, LARGE_SIGN]) {
	return {
		...PERSONALISED,
		v: SIGN_CONFIG_VERSION,
		blank: null,
		layout,
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

/** A variable product on no blank on the Personalise button, whose variations
 *  each have their own setup at one print size and no layout. */
async function createSign(
	request: APIRequestContext,
	name: string,
): Promise<string> {
	const response = await request.post(restUrl('/pixonaut-e2e/v1/products'), {
		data: {
			name,
			pixonaut: sign('button'),
			variations: Object.keys(SIGN_PRICES),
			prices: SIGN_PRICES,
			overrides: {
				Small: sign(null, [SMALL_SIGN]),
				Large: sign(null, [LARGE_SIGN]),
			},
		},
	});
	expect(response.status()).toBe(201);
	return (await response.json()).permalink;
}

function summaryPrice(page: Page) {
	return page
		.locator('.summary .price, .wc-block-components-product-price')
		.and(page.locator(':not(form *):not(.products *)'))
		.first();
}

test('a variation with its own setup and no layout follows its product’s button, from the choice to the cart', async ({
	page,
	request,
	fake,
	theme,
}) => {
	test.slow();
	const permalink = await createSign(
		request,
		`Button sign ${theme} ${Date.now()}`,
	);
	await page.goto(permalink);

	// The summary shows the range until a size is chosen, then that size's
	// price alone: WooCommerce's own variation price is left out.
	await expect(summaryPrice(page)).toContainText(SMALL_PRICE);
	await expect(summaryPrice(page)).toContainText(LARGE_PRICE);

	await page.locator('select[name="attribute_size"]').selectOption('Large');
	await expect(personaliseButton(page)).toBeVisible();
	await expect(page.getByText(/unavailable/)).toBeHidden();
	if (theme === 'twentytwentyfive') {
		await expect(personaliseButton(page)).toHaveClass(/\bwp-element-button\b/);
	}
	await expect(summaryPrice(page)).toContainText(LARGE_PRICE);
	await expect(summaryPrice(page)).not.toContainText(SMALL_PRICE);
	await expect(
		page.locator('.single_variation .woocommerce-variation-price'),
	).toBeHidden();

	await personaliseButton(page).click();
	await page.waitForURL((url) => url.origin === new URL(fake.baseURL).origin);
	await page.getByRole('link', { name: /^Approve and / }).click();
	await expect(page.getByText(/This is your design/)).toBeVisible();
	await expect(page.locator('input[name="variation_id"]')).not.toHaveValue('0');
	await expect(addToCartButton(page)).not.toHaveClass(/\bdisabled\b/);
	await addToCartButton(page).click();
	await expectAdded(page);
	expect(
		await statuses(fake, 'POST /v1/templates/{id}/designer-links'),
	).toContain(201);
	expect(await statuses(fake, 'POST /v1/designs')).toEqual([201]);
});

test('pressing the button with no size chosen asks for the size and stays on the product', async ({
	page,
	request,
	fake,
	theme,
}) => {
	test.slow();
	const permalink = await createSign(
		request,
		`Button no size ${theme} ${Date.now()}`,
	);
	await page.goto(permalink);

	await personaliseButton(page).click();

	await expect(
		page.getByText(
			'Please choose a value for size before personalising this product.',
		),
	).toBeVisible();
	expect(new URL(page.url()).pathname).toBe(new URL(permalink).pathname);
	await expect(personaliseButton(page)).toBeVisible();
	expect(await statuses(fake, 'POST /v1/designs')).toEqual([]);
});

test("the hosted designer shows the chosen variation's colour", async ({
	page,
	request,
	fake,
	theme,
}) => {
	test.slow();
	const response = await request.post(restUrl('/pixonaut-e2e/v1/products'), {
		data: {
			name: `Button colours ${theme} ${Date.now()}`,
			pixonaut: BUTTON_TEE,
			variations: ['White', 'Black'],
			variants: {
				White: FIXTURE.variant.teeWhiteS,
				Black: FIXTURE.variant.teeBlackS,
			},
		},
	});
	expect(response.status()).toBe(201);
	const permalink = (await response.json()).permalink;

	for (const colour of ['Black', 'White']) {
		await page.goto(permalink);
		await page.locator('select[name="attribute_size"]').selectOption(colour);
		await expect(personaliseButton(page)).toBeVisible();
		await expect(personaliseButton(page)).not.toHaveClass(/\bdisabled\b/);
		await personaliseButton(page).click();
		await page.waitForURL((url) => url.origin === new URL(fake.baseURL).origin);
		await expect(page.getByText(`Variant: ${colour}, S`)).toBeVisible();
		await expect(page.getByText('Views: all')).toBeVisible();
	}
});
