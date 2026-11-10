import type { APIRequestContext, Page } from '@playwright/test';
import {
	expect,
	type FakePixonaut,
	restUrl,
	TESTS_SITE_ON_NETWORK,
	test,
	WEBHOOK_ROUTE,
} from '../fixtures';
import { linkProduct, personaliseIntoCart, runJobs, statuses } from '../orders';
import { openPixonautTab } from '../products';

/** The template the linked product uses. */
const TEMPLATE = 'mini-tee';

/** The version publishing makes: the fixture's live one is 2. */
const PUBLISHED_VERSION = 3;

/** The jobs a catalogue event runs through: its handling, the queueing of
 *  the products it affects, and their re-check. */
const EVENT_JOBS = [
	'pixonaut_wc_webhook_process',
	'pixonaut_wc_catalogue_recheck_queue',
	'pixonaut_wc_catalogue_recheck',
];

/** Rounds of jobs run before the event's jobs must be done: handling,
 *  queueing and re-checking take one round each. */
const MAX_ROUNDS = 20;

/** Takes the products earlier tests and runs linked out of what a catalogue
 *  event re-checks: the tests site keeps them, and re-checking a few hundred
 *  outlasts the test's timeout. */
async function forgetOtherProducts(request: APIRequestContext): Promise<void> {
	const response = await request.delete(
		restUrl('/pixonaut-e2e/v1/product-configurations'),
	);
	expect(response.ok(), await response.text()).toBe(true);
}

/** Points the store's endpoint at the tests site, by its network name. */
async function receiveWebhooks(fake: FakePixonaut): Promise<void> {
	const endpoint = await fake.control.post('/__fake/webhooks/endpoint', {
		data: { url: `${TESTS_SITE_ON_NETWORK}${restUrl(WEBHOOK_ROUTE)}` },
	});
	expect(endpoint.ok()).toBe(true);
}

/** Changes the fake's catalogue; the store's endpoint takes the event. */
async function change(
	fake: FakePixonaut,
	path: string,
	data: Record<string, unknown>,
): Promise<void> {
	const response = await fake.control.post(`/__fake/${path}`, { data });
	expect(response.ok(), await response.text()).toBe(true);
	expect((await response.json()).attempts).toMatchObject([{ status: 200 }]);
}

/** Runs the plugin's jobs until those of the events received are done. A
 *  re-check retried later, of a product earlier runs left, is not waited for. */
async function settle(request: APIRequestContext): Promise<void> {
	for (let round = 0; round < MAX_ROUNDS; round++) {
		const pending: { hook: string; due: boolean }[] = await (
			await request.get(restUrl('/pixonaut-e2e/v1/jobs'))
		).json();
		if (!pending.some((job) => job.due && EVENT_JOBS.includes(job.hook))) {
			return;
		}
		await runJobs(request);
	}
	throw new Error('The catalogue event jobs did not settle.');
}

function addToCart(page: Page) {
	return page
		.locator('form.cart, form[data-wp-interactive]')
		.getByRole('button', { name: /add to cart/i });
}

test('a new template version keeps the product on sale; a deleted template takes it off', async ({
	connectedStore: admin,
	customer,
	fake,
	request,
	baseURL,
	theme,
}) => {
	test.slow();
	const name = `Re-checked tee ${theme} ${Date.now()}`;
	await forgetOtherProducts(request);
	const permalink = await linkProduct(admin, baseURL, name);
	await receiveWebhooks(fake);
	// The baseline queued at connect re-checks every product; let it finish.
	await settle(request);

	await change(fake, 'templates/publish', { template: TEMPLATE });
	await settle(request);

	await personaliseIntoCart(customer, permalink);
	// The product page designed on the version just published.
	const paths = (await fake.requests()).map((r) => decodeURIComponent(r.path));
	expect(paths).toContain(`/v1/templates/${TEMPLATE}@${PUBLISHED_VERSION}`);

	await change(fake, 'templates/delete', { template: TEMPLATE });
	await settle(request);

	await customer.goto(permalink);
	await expect(addToCart(customer)).toHaveCount(0);
	// WooCommerce dropped the cart's line, no longer for sale; its design goes.
	await runJobs(request);
	expect(await statuses(fake, 'DELETE /v1/designs/{id}')).toEqual([204]);
	await admin.goto('/wp-admin/');
	await expect(
		admin.locator('.pixonaut-notice', { hasText: name }),
	).toContainText('paused or deleted');
});

/** The names of the products the Store API lists for a search. */
async function storeApiNames(
	request: APIRequestContext,
	search: string,
): Promise<string[]> {
	const response = await request.get(
		`${restUrl('/wc/store/v1/products')}&search=${encodeURIComponent(search)}`,
	);
	expect(response.ok(), await response.text()).toBe(true);
	return ((await response.json()) as { name: string }[]).map((p) => p.name);
}

test('a paused template takes the product out of the shop; resuming it brings it back', async ({
	connectedStore: admin,
	customer,
	fake,
	request,
	baseURL,
	theme,
}) => {
	test.slow();
	const name = `Paused tee ${theme} ${Date.now()}`;
	// WooCommerce opens the product of a search that finds it alone.
	const search = `/?post_type=product&s=${encodeURIComponent(name)}`;
	const found = customer.getByRole('heading', {
		level: 1,
		name,
		exact: true,
	});
	await forgetOtherProducts(request);
	const permalink = await linkProduct(admin, baseURL, name);
	await receiveWebhooks(fake);
	await settle(request);

	await customer.goto(search);
	await expect(found).toBeVisible();
	expect(await storeApiNames(request, name)).toEqual([name]);

	await change(fake, 'templates/pause', { template: TEMPLATE });
	await settle(request);

	await customer.goto(search);
	await expect(found).toHaveCount(0);
	await expect(customer.getByRole('link', { name, exact: true })).toHaveCount(
		0,
	);
	expect(await storeApiNames(request, name)).toEqual([]);
	const page = await customer.goto(permalink);
	expect(page?.status()).toBe(200);
	await expect(customer.locator('.pixonaut-unavailable')).toHaveText(
		'This product is not available right now.',
	);
	await expect(addToCart(customer)).toHaveCount(0);
	await expect(customer.locator('.pixonaut-unavailable__note')).toHaveCount(0);
	// The store manager previews the page, and reads why shoppers do not see it.
	await admin.goto(permalink);
	await expect(admin.locator('.pixonaut-unavailable__note')).toContainText(
		'Shoppers do not see this product',
	);

	await change(fake, 'templates/resume', { template: TEMPLATE });
	await settle(request);

	await customer.goto(search);
	await expect(found).toBeVisible();
	expect(await storeApiNames(request, name)).toEqual([name]);
	await expect(customer.locator('.pixonaut-unavailable')).toHaveCount(0);
	await expect(addToCart(customer)).toBeVisible();
});

test("a template's changed id moves the product, and the cart line made before still buys", async ({
	connectedStore: admin,
	customer,
	fake,
	request,
	baseURL,
	theme,
}) => {
	test.slow();
	const name = `Renamed tee ${theme} ${Date.now()}`;
	const newId = `${TEMPLATE}-pandas`;
	await forgetOtherProducts(request);
	const permalink = await linkProduct(admin, baseURL, name);
	const edit = admin.url();
	await receiveWebhooks(fake);
	await settle(request);
	await personaliseIntoCart(customer, permalink);

	await change(fake, 'templates/rename', { template: TEMPLATE, newId });
	await settle(request);

	const carts = await request.post(restUrl('/pixonaut-e2e/v1/cart'));
	expect(carts.ok(), 'the tests site has a classic cart').toBe(true);
	const cart = (await carts.json()).classic as string;
	await customer.goto(cart);
	await expect(
		customer.locator('.woocommerce-cart-form .cart_item', { hasText: name }),
	).toHaveCount(1);

	// A design made now names the new id, and is added beside it.
	await personaliseIntoCart(customer, permalink);
	await customer.goto(cart);
	await expect(
		customer.locator('.woocommerce-cart-form .cart_item', { hasText: name }),
	).toHaveCount(2);

	await admin.goto(edit);
	await openPixonautTab(admin);
	await expect(
		admin.locator('.pixonaut-side .pixonaut-template-studio').first(),
	).toHaveAttribute('href', new RegExp(`/templates/${newId}$`));
});
