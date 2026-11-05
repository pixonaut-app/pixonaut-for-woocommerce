import type { APIRequestContext, Page } from '@playwright/test';
import { FIXTURE } from '../../fake-pixonaut/src/fixture-ids';
import { expect, restUrl, test } from '../fixtures';
import {
	addToCartButton,
	connectFixtureStore,
	createProduct,
	PERSONALISED,
	viewCartLink,
} from '../personalised';

/** The tee laid out one step at a time: a version 3 configuration, the
 *  first with a form layout of its own. */
const IN_STEPS = { ...PERSONALISED, v: 3, formLayout: 'steps' };

test.beforeEach(async ({ request, baseURL }) => {
	await connectFixtureStore(request, baseURL ?? '');
});

test.afterEach(async ({ request }) => {
	await request.delete(restUrl('/pixonaut-e2e/v1/connection'));
});

/** A variable tee in steps, sold in white (Tee) and black (Dark). */
async function createVariableTee(
	request: APIRequestContext,
	name: string,
): Promise<string> {
	const response = await request.post(restUrl('/pixonaut-e2e/v1/products'), {
		data: {
			name,
			pixonaut: IN_STEPS,
			variations: ['Tee', 'Dark'],
			variant: FIXTURE.variant.teeWhiteS,
			variants: { Dark: FIXTURE.variant.teeBlackS },
		},
	});
	expect(response.status()).toBe(201);
	return (await response.json()).permalink;
}

/** The designer's own form, which the steps are drawn in. */
function designerForm(page: Page) {
	return page.locator('#pixonaut-form-front');
}

test('a variable product’s steps open on its options and end with Add to cart', async ({
	page,
	request,
	theme,
}) => {
	await page.goto(
		await createVariableTee(request, `Steps ${theme} ${Date.now()}`),
	);
	const size = designerForm(page).locator('select[name="attribute_size"]');

	// The variation fields are the first step, offered once. They stand there
	// before the designer has drawn it, which Next below needs.
	await expect(page.locator('[data-testid="pixonaut-form"]')).toBeVisible();
	await expect(size).toBeVisible();
	await expect(page.locator('select[name="attribute_size"]')).toHaveCount(1);
	await expect(page.getByTestId('pixonaut-variant-picker')).toHaveCount(0);
	await expect(addToCartButton(page)).toBeHidden();

	await size.selectOption('Dark');
	const next = designerForm(page).getByRole('button', { name: /next/i });
	while (await next.isVisible()) {
		await next.click();
	}

	// The last step: Add to cart, drawn in the designer, sends the design.
	await expect(designerForm(page).locator('.pixonaut-cart-row')).toBeVisible();
	await expect(addToCartButton(page)).toBeVisible();
	await expect(addToCartButton(page)).not.toHaveClass(/\bdisabled\b/);
	const proofMade = page.waitForRequest(
		(r) => r.method() === 'POST' && new URL(r.url()).pathname === '/v1/proofs',
	);
	await addToCartButton(page).click();
	await proofMade;
	await expect(page.getByText(/has been added to your cart/)).toBeVisible();
	await viewCartLink(page).click();
	await expect(
		page
			.locator('.variation-Size, .wc-block-components-product-details__value')
			.filter({ hasText: 'Dark' }),
	).toBeVisible();
});

test('a one-page form ends with Add to cart, and the view strip keeps a gap under the preview', async ({
	page,
	request,
	theme,
}) => {
	await page.goto(
		await createProduct(
			request,
			`One page ${theme} ${Date.now()}`,
			PERSONALISED,
		),
	);
	const row = designerForm(page).locator('.pixonaut-cart-row');
	await expect(row).toBeVisible();
	await expect(row).toContainText(/add to cart/i);

	const strip = page.locator('#pixonaut-views-front > *').first();
	await expect(strip).toBeVisible();
	await expect(strip).toHaveCSS('padding-top', '12px');

	await addToCartButton(page).click();
	await expect(page.getByText(/has been added to your cart/)).toBeVisible();
});
