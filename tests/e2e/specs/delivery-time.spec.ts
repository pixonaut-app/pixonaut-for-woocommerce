import type { APIRequestContext, Page } from '@playwright/test';
import { FIXTURE } from '../../fake-pixonaut/src/fixture-ids';
import {
	expect,
	restUrl,
	settingsPath,
	test,
	usePlainPermalinks,
} from '../fixtures';
import { addToCartButton, PERSONALISED } from '../personalised';
import { openPixonautTab, openPrices, productPanel } from '../products';

/** Each size sells another of the tee's variants. The fake's printshop tells
 *  one time for both: 4 to 7 days from the order to the door. */
const VARIANTS = {
	Small: FIXTURE.variant.teeWhiteS,
	Large: FIXTURE.variant.teeWhiteM,
};

const PRICES = { Small: '150.00', Large: '150.00' };

const SHOW = '[id="pixonaut_wc_settings[delivery_time_show]"]';
const HANDLING_DAYS =
	'[id="pixonaut_wc_settings[delivery_time_handling_days]"]';

/** The line a product page says the delivery time in: the chosen
 *  variation's, of the place that holds every variation's. */
const LINE = '.pixonaut-delivery-time .is-chosen';

async function createProduct(
	request: APIRequestContext,
	name: string,
): Promise<{ id: number; permalink: string }> {
	const response = await request.post(restUrl('/pixonaut-e2e/v1/products'), {
		data: {
			name,
			pixonaut: PERSONALISED,
			variations: Object.keys(VARIANTS),
			variants: VARIANTS,
			prices: PRICES,
		},
	});
	expect(response.status()).toBe(201);
	return response.json();
}

/** Sets whether product pages say the delivery time, and the store's
 *  handling days, on the settings page's Prices tab. */
async function showDeliveryTime(
	admin: Page,
	shown: boolean,
	handlingDays: number,
): Promise<void> {
	await admin.goto(settingsPath('prices'));
	await admin.locator(SHOW).setChecked(shown);
	await admin.locator(HANDLING_DAYS).fill(String(handlingDays));
	await admin.getByRole('button', { name: 'Save changes' }).click();
	await expect(admin.getByText('Your settings have been saved.')).toBeVisible();
}

test('a product page says how long the printshop says the chosen variation takes, once the store shows delivery times', async ({
	connectedStore: admin,
	request,
	baseURL,
}) => {
	await usePlainPermalinks(admin, baseURL);
	const { id, permalink } = await createProduct(
		request,
		`Timed tee ${Date.now()}`,
	);
	try {
		// Opening a product's prices reads its costs, and their times.
		await admin.goto(`/wp-admin/post.php?post=${id}&action=edit`);
		await openPixonautTab(admin);
		await openPrices(admin);
		await expect(
			productPanel(admin).locator('.pixonaut-prices__row').first(),
		).toContainText('$124.90');

		// Off, as a store starts: the page says nothing of time.
		await admin.goto(permalink);
		await admin.locator('select[name="attribute_size"]').selectOption('Small');
		await expect(addToCartButton(admin)).not.toHaveClass(/\bdisabled\b/);
		await expect(admin.locator(LINE)).toHaveCount(0);

		await showDeliveryTime(admin, true, 0);
		await admin.goto(permalink);
		// Nothing is chosen yet, so there is no variation to tell the time of.
		await admin.locator('select[name="attribute_size"]').selectOption('');
		await expect(admin.locator(LINE)).toBeHidden();
		await admin.locator('select[name="attribute_size"]').selectOption('Small');
		await expect(admin.locator(LINE)).toHaveText('Delivered in 4–7 days');

		// The store's own days are added to the printshop's.
		await showDeliveryTime(admin, true, 2);
		await admin.goto(permalink);
		await admin.locator('select[name="attribute_size"]').selectOption('Large');
		await expect(admin.locator(LINE)).toHaveText('Delivered in 6–9 days');
	} finally {
		await showDeliveryTime(admin, false, 0);
	}
});
