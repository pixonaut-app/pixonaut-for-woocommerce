// A personalised product made through the tests site's support routes,
// without wp-admin, and the store connected to the fake as if it had
// connected.
import type { APIRequestContext, Page } from '@playwright/test';
import { FIXTURE } from '../fake-pixonaut/src/fixture-ids';
import { expect, restUrl } from './fixtures';

/** mini-tee's 280:360 ratio fitted into the front's 300×360 default box: the
 *  one size on the menu, which add to cart holds the design to. */
const FITTED_DEFAULT = { widthMm: 280, heightMm: 360, label: '280 × 360 mm' };

/** A product personalised on the fixture tee's front, inline. */
export const PERSONALISED = {
	v: 2,
	personalise: true,
	blank: { id: FIXTURE.blank.tee, name: 'Fixture tee' },
	sides: [
		{
			key: 'front',
			label: 'Front',
			placement: { id: FIXTURE.placement.front, name: 'Front' },
			template: { baseId: 'mini-tee', version: 2, name: 'Mini tee' },
			printSizes: [FITTED_DEFAULT],
		},
	],
	layout: 'inline',
	approvalMode: null,
	validity: { valid: true, reason: null },
	views: [] as string[],
};

/** The fixture store's keys, stored as if it had connected. */
export async function connectFixtureStore(
	request: APIRequestContext,
	siteUrl: string,
): Promise<void> {
	const { main } = FIXTURE.store;
	const response = await request.post(restUrl('/pixonaut-e2e/v1/connection'), {
		data: {
			tenantId: FIXTURE.tenant.live,
			tenantName: 'Fixture Prints',
			environment: 'live',
			connectionId: main.id,
			integrationKey: {
				id: 'ik_id_fixture_main',
				key: main.integrationKey,
				label: 'WooCommerce',
			},
			publishableKey: {
				id: 'pk_id_fixture_main',
				key: main.publishableKey,
				allowedOrigins: [new URL(siteUrl).origin],
			},
			webhook: {
				id: main.webhook.id,
				url: `${siteUrl}/wp-json/pixonaut/v1/webhook`,
				secret: main.webhook.secret,
			},
		},
	});
	expect(response.status(), 'the tests site stores the connection').toBe(204);
}

export async function createProduct(
	request: APIRequestContext,
	name: string,
	pixonaut?: object,
): Promise<string> {
	const response = await request.post(restUrl('/pixonaut-e2e/v1/products'), {
		data: { name, pixonaut },
	});
	expect(response.status()).toBe(201);
	return (await response.json()).permalink;
}

export function addToCartButton(page: Page) {
	return page
		.locator('form.cart, form[data-wp-interactive]')
		.getByRole('button', { name: /add to cart/i });
}

/** The link in the added-to-cart notice. Storefront's header mini cart has
 *  one too, once its cart fragments have loaded. */
export function viewCartLink(page: Page) {
	return page
		.locator('.woocommerce-message, .wc-block-components-notice-banner')
		.getByRole('link', { name: 'View cart' });
}

/** Adds a new personalised product named after `name` to the cart from its
 *  page. */
export async function addPersonalised(
	page: Page,
	request: APIRequestContext,
	name: string,
): Promise<void> {
	const permalink = await createProduct(
		request,
		`${name} ${Date.now()}`,
		PERSONALISED,
	);
	await page.goto(permalink);
	await expect(page.locator('#pixonaut-form-front *').first()).toBeVisible();
	await addToCartButton(page).click();
	await expect(page.getByText(/has been added to your cart/)).toBeVisible();
}

/** A published variable product with a variation per `sizes` value, linked
 *  in its Pixonaut tab with `pixonaut`; its id. */
export async function createVariableProduct(
	request: APIRequestContext,
	name: string,
	pixonaut: object,
	sizes: string[],
): Promise<number> {
	const response = await request.post(restUrl('/pixonaut-e2e/v1/products'), {
		data: { name, pixonaut, variations: sizes },
	});
	expect(response.status()).toBe(201);
	return (await response.json()).id;
}
