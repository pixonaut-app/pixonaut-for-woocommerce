import type { Locator, Page } from '@playwright/test';
import { expect, restUrl, test } from '../fixtures';
import {
	addPersonalised,
	addToCartButton,
	connectFixtureStore,
	createProduct,
	PERSONALISED,
} from '../personalised';

/** Each cart's address on the tests site. */
let cartUrls: Record<'classic' | 'block' | 'mini', string>;

test.beforeEach(async ({ request, baseURL }) => {
	await connectFixtureStore(request, baseURL ?? '');
	const response = await request.post(restUrl('/pixonaut-e2e/v1/cart'));
	expect(response.ok(), 'the tests site has both carts').toBe(true);
	cartUrls = await response.json();
});

test.afterEach(async ({ request }) => {
	await request.delete(restUrl('/pixonaut-e2e/v1/connection'));
});

/** The personalised line and its image in each cart. */
const CARTS: Record<
	'classic' | 'block',
	(page: Page) => { line: Locator; image: Locator; name: Locator }
> = {
	classic: (page) => {
		const line = page.locator('.woocommerce-cart-form .cart_item');
		return {
			line,
			image: line.locator('img.pixonaut-proof-thumbnail'),
			name: line.locator('.product-name a'),
		};
	},
	block: (page) => {
		const line = page.locator('.wc-block-cart-items__row').first();
		return {
			line,
			image: line.locator('.wc-block-cart-item__image img'),
			name: line.locator('a.wc-block-components-product-name'),
		};
	},
};

test('adding a personalised product lands on its edit page', async ({
	page,
	request,
	theme,
}) => {
	const lentWarnings: string[] = [];
	page.on('console', (message) => {
		if (message.text().includes('lent element')) {
			lentWarnings.push(message.text());
		}
	});
	await addPersonalised(page, request, `Lands ${theme}`);

	await expect(page).toHaveURL(/[?&]pixonaut_edit=[0-9a-f]+/);
	await expect(
		page
			.locator('form.cart, form[data-wp-interactive]')
			.getByRole('button', { name: 'Update design' }),
	).toBeVisible();
	expect(lentWarnings, 'on the product page and from the cart link').toEqual(
		[],
	);
});

test('the edit page shows the design on the product as the product page did', async ({
	page,
	request,
	fake,
	theme,
}) => {
	const permalink = await createProduct(
		request,
		`Same look ${theme} ${Date.now()}`,
		PERSONALISED,
	);
	const mockupReads = async () =>
		(await fake.requests()).filter(
			(r) => r.operation === 'GET /v1/blanks/{id}/mockups' && r.status === 200,
		).length;
	const strip = page.getByTestId('pixonaut-view-strip').first();

	await page.goto(permalink);
	await expect(strip).toBeVisible();
	const productViews = await strip.ariaSnapshot();
	const productReads = await mockupReads();
	await addToCartButton(page).click();

	await expect(page).toHaveURL(/[?&]pixonaut_edit=[0-9a-f]+/);
	await expect(
		page
			.locator('form.cart, form[data-wp-interactive]')
			.getByRole('button', { name: 'Update design' }),
	).toBeVisible();
	// The edit page reads the blank's photos again, through the correction link.
	await expect.poll(mockupReads).toBeGreaterThan(productReads);
	// The same views, in the same variant, as the product page showed.
	await expect.poll(() => strip.ariaSnapshot()).toBe(productViews);
});

for (const cart of ['classic', 'block'] as const) {
	test(`a design is edited from the ${cart} cart and its new proof replaces the old one`, async ({
		page,
		request,
		theme,
		fake,
	}) => {
		await addPersonalised(page, request, `Edit ${cart} ${theme}`);
		await page.goto(cartUrls[cart]);
		const { line, image, name } = CARTS[cart](page);
		await expect(image).toHaveAttribute('src', /.+/);
		const before = (await image.getAttribute('src')) ?? '';

		await name.click();
		await expect(page).toHaveURL(/pixonaut_edit=/);
		const form = page.locator('#pixonaut-form-front');
		await expect(form.getByRole('textbox').first()).toBeVisible();
		await form.getByRole('textbox').first().fill(`Edited in the ${cart} cart`);
		await page
			.locator('form.cart, form[data-wp-interactive]')
			.getByRole('button', { name: 'Update design' })
			.click();
		await page.waitForURL((url) => !url.search.includes('pixonaut_edit'));

		await page.goto(cartUrls[cart]);
		await expect(image).not.toHaveAttribute('src', before);
		await expect(line).toHaveCount(1);
		const requests = await fake.requests();
		const statuses = (operation: string) =>
			requests.filter((r) => r.operation === operation).map((r) => r.status);
		// One for the edit page the add landed on, one for the edit from the cart.
		expect(statuses('POST /v1/designs/{id}/correction-links')).toEqual([
			201, 201,
		]);
		expect(statuses('POST /v1/designs/{id}/correction-proofs')).toEqual([201]);
		expect(statuses('PATCH /v1/designs/{id}')).toEqual([200]);

		// Editing again starts from the saved correction.
		await name.click();
		await expect(page).toHaveURL(/pixonaut_edit=/);
		await expect(form.getByRole('textbox').first()).toHaveValue(
			`Edited in the ${cart} cart`,
		);
	});
}
