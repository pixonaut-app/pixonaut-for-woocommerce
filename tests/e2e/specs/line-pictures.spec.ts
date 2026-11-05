import { expect, restUrl, test } from '../fixtures';
import { openCheckouts, pay } from '../orders';
import { addPersonalised, connectFixtureStore } from '../personalised';

/** The plugin's proof route asked for the design on its blank: under
 *  `/wp-json/` or, on plain permalinks, encoded in `rest_route`. */
const ON_BLANK =
	/(\/wp-json\/|rest_route=%2F)pixonaut(\/|%2F)v1(\/|%2F)proof(\/|%2F).*[?&]p=blank/;

test('the cart and the order received page show the design on its blank', async ({
	page,
	request,
	theme,
	checkout,
	fakeBaseURL,
	baseURL,
}) => {
	await connectFixtureStore(request, baseURL ?? '');
	const carts = await (
		await request.post(restUrl('/pixonaut-e2e/v1/cart'))
	).json();
	const checkoutUrls = await openCheckouts(request);
	await addPersonalised(page, request, `Picture ${theme}`);
	const fakeOrigin = new URL(fakeBaseURL).origin;

	await page.goto(carts.classic);
	await expect(
		page.locator('.woocommerce-cart-form img.pixonaut-proof-thumbnail'),
	).toHaveAttribute('src', ON_BLANK);

	await pay(page, checkout, checkoutUrls);
	const picture = page.locator('img.pixonaut-line-picture');
	await expect(picture).toHaveCount(1);
	await expect(picture).toHaveAttribute('src', ON_BLANK);
	expect((await picture.getAttribute('src')) ?? '').not.toContain(fakeOrigin);
	await expect
		.poll(() => picture.evaluate((img: HTMLImageElement) => img.naturalWidth))
		.toBeGreaterThan(0);
});
