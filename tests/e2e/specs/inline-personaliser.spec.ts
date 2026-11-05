import { FIXTURE } from '../../fake-pixonaut/src/fixture-ids';
import { expect, restUrl, SDK_SCRIPT, test } from '../fixtures';
import {
	addToCartButton,
	connectFixtureStore,
	createProduct,
	PERSONALISED,
} from '../personalised';

/** A print size the product is not sold in. */
const TAMPERED_SIZE = { widthMm: 140, heightMm: 180 };

test.beforeEach(async ({ request, baseURL }) => {
	await connectFixtureStore(request, baseURL ?? '');
});

test.afterEach(async ({ request }) => {
	await request.delete(restUrl('/pixonaut-e2e/v1/connection'));
});

test('a personalised product shows the preview and adds a proof token to the cart', async ({
	page,
	request,
	fake,
	theme,
}) => {
	const permalink = await createProduct(
		request,
		`Personalised ${theme} ${Date.now()}`,
		PERSONALISED,
	);

	await page.goto(permalink);

	await expect(page.locator('#pixonaut-preview-front')).toBeAttached();
	await expect(
		page.locator(
			'.woocommerce-product-gallery, .wp-block-woocommerce-product-image-gallery, .wp-block-woocommerce-product-gallery',
		),
	).toHaveCount(0);
	const sdk = page.locator(`script[src="${SDK_SCRIPT.url}"]`);
	await expect(sdk).toHaveAttribute('integrity', SDK_SCRIPT.integrity);
	await expect(sdk).toHaveAttribute('crossorigin', 'anonymous');
	await expect(page.locator('#pixonaut-form-front *').first()).toBeVisible();
	await expect(page.locator('#pixonaut-preview-front *').first()).toBeVisible();

	const added = page.waitForRequest(
		(r) => r.method() === 'POST' && r.resourceType() === 'document',
	);
	await addToCartButton(page).click();
	const sent = await added;
	const body = sent.postData() ?? '';
	// Handled once the server answered.
	await sent.response();

	const token = /name="pixonaut_designs\[front\]"\r\n\r\n([^\r]*)/.exec(
		body,
	)?.[1];
	expect(token).toMatch(/\S/);
	const requests = await fake.requests();
	const statuses = (operation: string) =>
		requests.filter((r) => r.operation === operation).map((r) => r.status);
	expect(statuses('POST /v1/proofs')).toEqual([201]);
	// Add to cart commits the proof the browser made.
	expect(statuses('POST /v1/designs')).toEqual([201]);
});

test('a product that is not personalised loads nothing of the plugin', async ({
	page,
	request,
	theme,
	blocks,
}) => {
	const sdkRequests: string[] = [];
	page.on('request', (r) => {
		if (r.url().startsWith('https://cdn.pixonaut.app/')) {
			sdkRequests.push(r.url());
		}
	});
	const permalink = await createProduct(
		request,
		`Plain ${theme} ${Date.now()}`,
	);

	await page.goto(permalink);
	await page.waitForLoadState('networkidle');

	await expect(page.locator('script[src*="build/storefront.js"]')).toHaveCount(
		0,
	);
	await expect(page.locator('link[href*="build/storefront.css"]')).toHaveCount(
		0,
	);
	await expect(page.locator('.pixonaut-form, .pixonaut-preview')).toHaveCount(
		0,
	);
	expect(sdkRequests).toEqual([]);
	if (blocks === 'secondary') {
		await expect(
			page.locator('form[data-wp-on--submit="actions.addToCart"]'),
		).toHaveCount(1);
	}
});

test('a personalised product posts the add-to-cart form on every block pair', async ({
	page,
	request,
	theme,
}) => {
	const permalink = await createProduct(
		request,
		`Form ${theme} ${Date.now()}`,
		PERSONALISED,
	);

	await page.goto(permalink);

	const form = page.locator('form:has(.pixonaut-form)');
	await expect(form).toHaveAttribute('method', 'post');
	await expect(form).not.toHaveAttribute('data-wp-on--submit', /.*/);
	await expect(
		form.locator('input[name="pixonaut_designs[front]"]'),
	).toHaveCount(1);
});

test('a proof tampered with on its way to the cart is refused and its design deleted', async ({
	page,
	request,
	fake,
	theme,
}) => {
	const permalink = await createProduct(
		request,
		`Tampered ${theme} ${Date.now()}`,
		PERSONALISED,
	);
	// A genuine proof of the tee's front, at a size the product is not sold in.
	const minted = await fake.control.post('/__fake/proofs', {
		data: { templateId: FIXTURE.template.live, printSize: TAMPERED_SIZE },
	});
	expect(minted.ok()).toBe(true);
	const { proofToken } = (await minted.json()) as { proofToken: string };
	// Swaps the browser's proof for it in the add-to-cart post.
	await page.route(
		(url) => url.href === permalink,
		async (route) => {
			const body = route.request().postData();
			if (route.request().method() !== 'POST' || body === null) {
				return route.fallback();
			}
			return route.fallback({
				postData: body.replace(
					/(name="pixonaut_designs\[front\]"\r\n\r\n)[^\r]*/,
					`$1${proofToken}`,
				),
			});
		},
	);

	await page.goto(permalink);
	await expect(page.locator('#pixonaut-preview-front *').first()).toBeVisible();
	await addToCartButton(page).click();

	await expect(
		page.getByText(
			'Your design does not match this product. Please personalise it again.',
		),
	).toBeVisible();
	await expect(page.getByText(/has been added to your cart/)).toHaveCount(0);
	const requests = await fake.requests();
	const statuses = (operation: string) =>
		requests.filter((r) => r.operation === operation).map((r) => r.status);
	expect(statuses('POST /v1/designs')).toEqual([201]);
	expect(statuses('DELETE /v1/designs/{id}')).toEqual([204]);
});

test('the designer shows the blank’s views in the product’s variant', async ({
	page,
	request,
	fake,
	theme,
}) => {
	const permalink = await createProduct(
		request,
		`Views ${theme} ${Date.now()}`,
		PERSONALISED,
	);

	const pageHtml = await (await request.get(permalink)).text();
	// The page prints the views strip from the server's read of them.
	expect(pageHtml).toContain('class="pixonaut-views__view"');
	expect(pageHtml).toContain(
		`data-pixonaut-variant="${FIXTURE.variant.teeWhiteS}"`,
	);

	await page.goto(permalink);

	await expect(page.locator('#pixonaut-form-front *').first()).toBeVisible();
	const read = async (operation: string) =>
		(await fake.requests())
			.filter((r) => r.operation === operation)
			.map((r) => r.status);
	await expect.poll(() => read('GET /v1/blanks/{id}/mockups')).toContain(200);
	await expect.poll(() => read('GET /v1/blanks/{id}/variants')).toContain(200);
	await expect.poll(() => read('GET /i/{token}')).toContain(200);
	// Sold in one variant, the product has no colour or size to pick.
	await expect(page.getByTestId('pixonaut-view-strip').first()).toBeVisible();
	await expect(page.getByTestId('pixonaut-variant-picker')).toHaveCount(0);
});
