import type { APIRequestContext, Page } from '@playwright/test';
import { FIXTURE } from '../fake-pixonaut/src/fixture-ids';
import {
	expect,
	type FakePixonaut,
	restUrl,
	usePlainPermalinks,
} from './fixtures';
import { createProduct } from './personalised';
import { publishLinkedProduct } from './products';

/** The fulfilment job's Action Scheduler hook. */
export const FULFIL_HOOK = 'pixonaut_fulfil_item';

/** What the customer writes on the fixture tee's text layer. */
const SLOGAN = 'Printed by the journey';

/** A customer the store ships to: an address in the store's own country and
 *  state, so the checkouts' defaults fit it. */
const CUSTOMER = {
	firstName: 'Ada',
	lastName: 'Lovelace',
	address: '1 Market Street',
	city: 'San Francisco',
	postcode: '94105',
	phone: '5555550100',
	email: 'ada@example.com',
};

/** How long one click on the block checkout's Place Order may take to leave
 *  the page, and how long all of them may take. */
const PLACE_ORDER_ATTEMPT_MS = 10_000;
const PLACE_ORDER_TIMEOUT_MS = 60_000;

export type Line = {
	itemId: number;
	state: string;
	reason: string | null;
	refs: Record<string, unknown>;
	sides: Record<string, Record<string, unknown>>;
};

export type Order = {
	status: string;
	lines: Line[];
	notes: string[];
	editUrl: string;
};

/** Each checkout's address; the project's `checkout` option picks one. */
export type CheckoutUrls = Record<'classic' | 'block', string>;

/** Lets the tests site take orders, and answers where each checkout is. */
export async function openCheckouts(
	request: APIRequestContext,
): Promise<CheckoutUrls> {
	const response = await request.post(restUrl('/pixonaut-e2e/v1/checkout'));
	expect(response.ok(), 'the tests site takes orders').toBe(true);
	return response.json();
}

export async function readOrder(
	request: APIRequestContext,
	orderId: string,
): Promise<Order> {
	const response = await request.get(
		restUrl(`/pixonaut-e2e/v1/orders/${orderId}`),
	);
	expect(response.ok()).toBe(true);
	return response.json();
}

/** The order items the plugin has a pending fulfilment job for, one entry
 *  per job. */
export async function pendingFulfilment(
	request: APIRequestContext,
): Promise<number[]> {
	const response = await request.get(restUrl('/pixonaut-e2e/v1/jobs'));
	expect(response.ok()).toBe(true);
	const jobs = (await response.json()) as {
		hook: string;
		args: { item_id: number };
	}[];
	return jobs.filter((j) => j.hook === FULFIL_HOOK).map((j) => j.args.item_id);
}

/** Runs the plugin's due background jobs, as Action Scheduler would; with
 *  `early`, also the ones scheduled for later, such as retries; with `hook`,
 *  that hook's jobs, due or not, and with `item` only that order item's. */
export async function runJobs(
	request: APIRequestContext,
	{
		early = false,
		hook,
		item,
	}: { early?: boolean; hook?: string; item?: number } = {},
): Promise<void> {
	const params: Record<string, string> = early ? { early: '1' } : {};
	if (hook !== undefined) {
		params.hook = hook;
	}
	if (item !== undefined) {
		params.item = String(item);
	}
	const response = await request.post(restUrl('/pixonaut-e2e/v1/jobs'), {
		params,
	});
	expect(response.ok(), 'the tests site runs its jobs').toBe(true);
}

/** The statuses the fake answered an operation with, in order. */
export async function statuses(
	fake: FakePixonaut,
	operation: string,
): Promise<number[]> {
	return (await fake.requests())
		.filter((r) => r.operation === operation)
		.map((r) => r.status);
}

/** Pays for the cart at the project's checkout; answers the order's id. */
export async function pay(
	page: Page,
	checkout: 'classic' | 'block',
	urls: CheckoutUrls,
): Promise<string> {
	await page.goto(urls[checkout]);
	await (checkout === 'classic'
		? payAtClassicCheckout(page)
		: payAtBlockCheckout(page));
	await page.waitForURL(/order-received/);
	const orderId = /order-received[=/](\d+)/.exec(page.url())?.[1] ?? '';
	expect(orderId).toMatch(/^\d+$/);
	return orderId;
}

/** Fills the shortcode checkout and places the order. */
async function payAtClassicCheckout(page: Page): Promise<void> {
	await page.locator('#billing_first_name').fill(CUSTOMER.firstName);
	await page.locator('#billing_last_name').fill(CUSTOMER.lastName);
	await page.locator('#billing_address_1').fill(CUSTOMER.address);
	await page.locator('#billing_city').fill(CUSTOMER.city);
	await page.locator('#billing_postcode').fill(CUSTOMER.postcode);
	await page.locator('#billing_phone').fill(CUSTOMER.phone);
	await page.locator('#billing_email').fill(CUSTOMER.email);
	await page.locator('#payment_method_cod').check();
	await page.locator('#place_order').click();
}

/** Fills the block checkout and places the order. */
async function payAtBlockCheckout(page: Page): Promise<void> {
	const checkout = page.locator('.wp-block-woocommerce-checkout');
	await checkout.getByLabel('Email address').fill(CUSTOMER.email);
	const shipping = checkout.locator('#shipping');
	await shipping.getByLabel('First name').fill(CUSTOMER.firstName);
	await shipping.getByLabel('Last name').fill(CUSTOMER.lastName);
	await shipping.getByLabel('Address', { exact: true }).fill(CUSTOMER.address);
	await shipping.getByLabel('City').fill(CUSTOMER.city);
	await shipping.getByLabel(/ZIP Code/).fill(CUSTOMER.postcode);
	await checkout.getByLabel('Cash on delivery').check();
	const placeOrder = checkout.getByRole('button', { name: 'Place Order' });
	// The checkout drops a click that lands while it still saves the address
	// just typed; one that is taken disables the button, so a repeat is a no-op.
	await expect(async () => {
		if (await placeOrder.isEnabled()) {
			await placeOrder.click();
		}
		await expect(page).toHaveURL(/order-received/, {
			timeout: PLACE_ORDER_ATTEMPT_MS,
		});
	}).toPass({ timeout: PLACE_ORDER_TIMEOUT_MS });
}

/** The configuration the product screen saves for publishLinkedProduct's
 *  defaults: the fixture tee's front with the T-shirt template, at the size
 *  the placement suggests. */
const LINKED_TEE = {
	v: 14,
	personalise: true,
	blank: { id: FIXTURE.blank.tee, name: 'Fixture tee' },
	sides: [
		{
			key: 'front',
			label: 'Front',
			placement: { id: FIXTURE.placement.front, name: 'Front' },
			template: {
				baseId: 'mini-tee',
				version: 2,
				name: 'T-shirt med tryck, framsida',
				offersProperties: true,
				latestVersion: 2,
			},
			printSizes: [
				{
					widthMm: 280,
					heightMm: 360,
					label: '280 × 360 mm',
					placementDefault: true,
				},
			],
		},
	],
	validity: { valid: true, reason: null },
};

/** The blank variant publishLinkedProduct sells by default. */
const LINKED_TEE_VARIANT = 'v_tee_white_s';

/**
 * A new product linked as the product screen links it, made through the
 * tests site's support route rather than wp-admin: for a test about what
 * happens to it after, not about linking. Answers its address.
 */
export async function createLinkedProduct(
	admin: Page,
	baseURL: string | undefined,
	name: string,
): Promise<string> {
	await usePlainPermalinks(admin, baseURL);
	return createProduct(admin.request, name, LINKED_TEE, LINKED_TEE_VARIANT);
}

/** The merchant links a new product; answers its address. */
export async function linkProduct(
	admin: Page,
	baseURL: string | undefined,
	name: string,
): Promise<string> {
	await usePlainPermalinks(admin, baseURL);
	await publishLinkedProduct(admin, name, '25');
	const permalink = await admin
		.locator('#wp-admin-bar-view a, #view-post-btn a')
		.first()
		.getAttribute('href');
	expect(permalink).toBeTruthy();
	return permalink ?? '';
}

/** The customer personalises the product at `permalink` and adds it to the
 *  cart. */
export async function personaliseIntoCart(
	customer: Page,
	permalink: string,
): Promise<void> {
	await customer.goto(permalink);
	await expect(customer.locator('.pixonaut-preview *').first()).toBeVisible();
	await customer.getByRole('textbox', { name: /Slogan/ }).fill(SLOGAN);
	const proof = customer.waitForRequest(
		(r) => r.method() === 'POST' && new URL(r.url()).pathname === '/v1/proofs',
	);
	await customer
		.locator('form.cart, form[data-wp-interactive]')
		.getByRole('button', { name: /add to cart/i })
		.click();
	expect((await proof).postData()).toContain(SLOGAN);
	await expect(customer.getByText(/has been added to your cart/)).toBeVisible();
}

/**
 * A new linked product, which a guest personalises and adds to the cart. The
 * merchant links it on the product screen with `linkedInAdmin`; otherwise
 * the tests site makes it as that screen would.
 */
export async function addPersonalisedProduct({
	admin,
	customer,
	fake,
	baseURL,
	name,
	linkedInAdmin = false,
}: {
	admin: Page;
	customer: Page;
	fake: FakePixonaut;
	baseURL: string | undefined;
	name: string;
	linkedInAdmin?: boolean;
}): Promise<void> {
	const permalink = await (linkedInAdmin ? linkProduct : createLinkedProduct)(
		admin,
		baseURL,
		name,
	);
	await personaliseIntoCart(customer, permalink);
	expect(await statuses(fake, 'POST /v1/designs')).toEqual([201]);
}

/**
 * The merchant links a new product, and a guest personalises it, adds it to
 * the cart and pays at the project's checkout.
 *
 * @returns The WooCommerce order's id.
 */
export async function buyPersonalisedProduct({
	checkout,
	checkoutUrls,
	...product
}: Parameters<typeof addPersonalisedProduct>[0] & {
	checkout: 'classic' | 'block';
	checkoutUrls: CheckoutUrls;
}): Promise<string> {
	await addPersonalisedProduct(product);
	return pay(product.customer, checkout, checkoutUrls);
}
