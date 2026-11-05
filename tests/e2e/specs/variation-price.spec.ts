import type { APIRequestContext, Page } from '@playwright/test';
import { FIXTURE } from '../../fake-pixonaut/src/fixture-ids';
import { expect, restUrl, test } from '../fixtures';
import {
	addToCartButton,
	connectFixtureStore,
	PERSONALISED,
} from '../personalised';

const SMALL = { widthMm: 210, heightMm: 270, label: 'Small' };

/** Small prints at its own size; Large at the product's. */
const OVERRIDES = {
	Small: {
		...PERSONALISED,
		sides: [{ ...PERSONALISED.sides[0], printSizes: [SMALL] }],
	},
};

/** Each size's price, told apart by its whole units. */
const PRICES = { Small: '23.00', Large: '47.00' };

const SMALL_PRICE = /23[.,]00/;
const LARGE_PRICE = /47[.,]00/;

/** The product summary's price in Storefront and in Twenty Twenty-Five,
 *  outside the add-to-cart form and any list of products. */
function summaryPrice(page: Page) {
	return page
		.locator('.summary .price, .wc-block-components-product-price')
		.and(page.locator(':not(form *):not(.products *)'))
		.first();
}

function sizeSelect(page: Page) {
	return page.locator('select[name="attribute_size"]');
}

async function chooseSize(page: Page, size: string): Promise<void> {
	await sizeSelect(page).selectOption(size);
	await expect(addToCartButton(page)).not.toHaveClass(/\bdisabled\b/);
}

async function createProduct(
	request: APIRequestContext,
	name: string,
): Promise<string> {
	const response = await request.post(restUrl('/pixonaut-e2e/v1/products'), {
		data: {
			name,
			pixonaut: PERSONALISED,
			variations: Object.keys(PRICES),
			prices: PRICES,
			overrides: OVERRIDES,
			variant: FIXTURE.variant.teeWhiteS,
		},
	});
	expect(response.status()).toBe(201);
	return (await response.json()).permalink;
}

test.beforeEach(async ({ request, baseURL }) => {
	await connectFixtureStore(request, baseURL ?? '');
});

test.afterEach(async ({ request }) => {
	await request.delete(restUrl('/pixonaut-e2e/v1/connection'));
});

test('the product page shows the chosen size’s price, and the range again once none is chosen', async ({
	page,
	request,
	theme,
}) => {
	await page.goto(
		await createProduct(request, `Priced ${theme} ${Date.now()}`),
	);
	const price = summaryPrice(page);
	await expect(price).toContainText(SMALL_PRICE);
	await expect(price).toContainText(LARGE_PRICE);

	await chooseSize(page, 'Small');
	await expect(price).toContainText(SMALL_PRICE);
	await expect(price).not.toContainText(LARGE_PRICE);

	await chooseSize(page, 'Large');
	await expect(price).toContainText(LARGE_PRICE);
	await expect(price).not.toContainText(SMALL_PRICE);

	await sizeSelect(page).selectOption('');
	await expect(price).toContainText(SMALL_PRICE);
	await expect(price).toContainText(LARGE_PRICE);
});

test('a theme’s price target in the add-to-cart form takes the price instead of the summary', async ({
	page,
	request,
	theme,
}) => {
	await page.goto(
		await createProduct(request, `Priced target ${theme} ${Date.now()}`),
	);
	// As a theme prints it, after the plugin's form and before the quantity.
	await page.locator('.pixonaut-form').evaluate((form) => {
		form.insertAdjacentHTML(
			'afterend',
			'<div class="pixonaut-shop-price" data-pixonaut-price>Price</div>',
		);
	});
	// Listening before the size is chosen, so the event cannot fire first.
	await page.locator('.pixonaut-form').evaluate((form) => {
		form.addEventListener(
			'pixonaut:price',
			(event) => {
				(window as unknown as { priceDetail: unknown }).priceDetail = (
					event as CustomEvent
				).detail;
			},
			{ once: true },
		);
	});

	await chooseSize(page, 'Large');

	const target = page.locator('[data-pixonaut-price]');
	await expect(target).toContainText(LARGE_PRICE);
	await expect(target).toHaveClass('pixonaut-shop-price');
	await expect(summaryPrice(page)).toContainText(SMALL_PRICE);
	const variationId = Number(
		await page.locator('input[name="variation_id"]').inputValue(),
	);
	const detail = await page.waitForFunction(
		() => (window as unknown as { priceDetail?: unknown }).priceDetail,
	);
	expect(await detail.jsonValue()).toMatchObject({
		side: 'front',
		printSizeIndex: 0,
		variationId,
		priceHtml: expect.stringMatching(LARGE_PRICE),
	});
});
