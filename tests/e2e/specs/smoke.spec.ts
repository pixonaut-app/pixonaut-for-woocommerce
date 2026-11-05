import { expect, restUrl, test } from '../fixtures';

test('the plugin is active alongside WooCommerce', async ({ request }) => {
	const response = await request.get(restUrl('/pixonaut-e2e/v1/status'));
	expect(response.ok()).toBe(true);

	const status = await response.json();
	expect(status.active).toBe(true);
	expect(status.woocommerce).toEqual(expect.any(String));
});

test('a product page renders on the theme', async ({
	page,
	request,
	theme,
}) => {
	const name = `Smoke product ${theme} ${Date.now()}`;
	const created = await request.post(restUrl('/pixonaut-e2e/v1/products'), {
		data: { name },
	});
	expect(created.status()).toBe(201);
	const { permalink } = await created.json();

	const response = await page.goto(permalink);

	expect(response?.status()).toBe(200);
	await expect(page.locator('body')).toHaveClass(
		new RegExp(`wp-theme-${theme}`),
	);
	await expect(page.locator('body')).toHaveClass(/single-product/);
	await expect(page.getByRole('heading', { level: 1, name })).toBeVisible();
	await expect(page.locator('form.cart')).toBeVisible();
});
