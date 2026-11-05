import type { APIRequestContext, Page } from '@playwright/test';
import {
	connectionCard,
	expect,
	restUrl,
	SETTINGS_PATH,
	test,
} from '../fixtures';

const SWITCH_NAME = 'Allow Pixel to make changes to your store';

/** The credential the fake holds for the tests site; undefined when none. */
async function heldCredential(fake: {
	control: APIRequestContext;
}): Promise<string | undefined> {
	const { credentials } = (await (
		await fake.control.get('/__fake/store-access')
	).json()) as { credentials: { credential: string }[] };
	return credentials[0]?.credential;
}

/** Calls a shop tool as Pixonaut does. */
async function callTool(page: Page, credential: string) {
	return page.request.post(restUrl('/pixonaut/v1/shop-tools/list-products'), {
		headers: { authorization: `Bearer ${credential}` },
		data: {},
	});
}

// The settings screens look the same under every theme.
test.skip(
	({ theme }) => theme !== 'storefront',
	'wp-admin does not depend on the theme',
);

test('the switch issues a credential that reaches the shop tools, and cuts it at once', async ({
	connectedStore,
	fake,
}) => {
	const card = connectionCard(connectedStore);
	const toggle = card.getByRole('switch', { name: SWITCH_NAME });
	await expect(toggle).toHaveAttribute('aria-checked', 'false');
	expect(await heldCredential(fake)).toBeUndefined();

	await toggle.click();
	await expect(toggle).toHaveAttribute('aria-checked', 'true');
	const credential = await heldCredential(fake);
	expect(credential).toBeTruthy();
	const allowed = await callTool(connectedStore, credential ?? '');
	expect(allowed.status()).toBe(200);

	await card.getByRole('switch', { name: SWITCH_NAME }).click();
	await expect(card.locator('.pixonaut-store-access')).toContainText(
		'Pixonaut cannot turn it back on',
	);
	expect(await heldCredential(fake)).toBeUndefined();
	const refused = await callTool(connectedStore, credential ?? '');
	expect(refused.status()).toBe(403);
	expect(await refused.json()).toMatchObject({ code: 'store_access_off' });
});

test('turning it on as the store connects hands over the credential', async ({
	admin,
	fake,
}) => {
	await admin.goto(SETTINGS_PATH);
	await admin.getByRole('switch', { name: SWITCH_NAME }).check();
	await admin.getByRole('button', { name: 'Connect to Pixonaut' }).click();
	await admin.waitForURL(/[?&]pixonaut_connect=/);

	expect(await heldCredential(fake)).toBeTruthy();
	await expect(
		connectionCard(admin).getByRole('switch', { name: SWITCH_NAME }),
	).toHaveAttribute('aria-checked', 'true');
});
