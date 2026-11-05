import type { Page } from '@playwright/test';
import { connectionCard, expect, SETTINGS_PATH, test } from '../fixtures';

/** Read by the e2e support mu-plugin: lets this browser context set the
 *  browser's and the studio's Pixonaut addresses, which the tests site's
 *  constants otherwise fix. */
const HOSTS_COOKIE = 'pixonaut_e2e_hosts';

/** A local Pixonaut, as a developer would point the store at. */
const LOCAL_PIXONAUT = 'http://localhost:8787';

/** Clicks or Home presses that unlock the section. */
const PRESSES_TO_UNLOCK = 7;

test.skip(
	({ theme }) => theme !== 'storefront',
	'wp-admin does not depend on the theme',
);

function section(page: Page) {
	return page.locator('#pixonaut-developer-tools');
}

function heading(page: Page) {
	return page.locator('.pixonaut-connection > h2');
}

test('unlocks with the heading, stays unlocked, and sets and resets a base URL', async ({
	admin,
	context,
	baseURL,
}) => {
	await context.addCookies([
		{ name: HOSTS_COOKIE, value: 'unlocked', url: baseURL },
	]);
	await admin.goto(SETTINGS_PATH);
	await expect(section(admin)).toBeHidden();

	for (let click = 0; click < PRESSES_TO_UNLOCK - 2; click++) {
		await heading(admin).click();
	}
	await expect(admin.locator('.pixonaut-developer-countdown')).toHaveText(
		'2 more to enable developer tools',
	);
	await heading(admin).click();
	await heading(admin).click();
	await expect(section(admin)).toBeVisible();

	await admin.reload();
	await expect(section(admin)).toBeVisible();

	await section(admin)
		.getByLabel('API base', { exact: true })
		.fill(LOCAL_PIXONAUT);
	await section(admin)
		.getByRole('button', { name: 'Save developer tools' })
		.click();
	await expect(section(admin).locator('.pixonaut-developer-result')).toHaveText(
		'Developer tools saved.',
	);
	await expect(
		section(admin).getByLabel('API base', { exact: true }),
	).toHaveValue(LOCAL_PIXONAUT);
	await expect(section(admin)).toContainText(`In use: ${LOCAL_PIXONAUT}`);
	await expect(
		connectionCard(admin).locator('.pixonaut-custom-host'),
	).toHaveText('Custom Pixonaut host');
	await expect(connectionCard(admin)).toContainText(LOCAL_PIXONAUT);

	await section(admin)
		.locator('tr', { has: admin.getByLabel('API base', { exact: true }) })
		.getByRole('button', { name: 'Reset' })
		.click();
	await expect(
		section(admin).getByLabel('API base', { exact: true }),
	).toHaveValue('');
	await expect(section(admin)).toContainText(
		'In use: https://api.pixonaut.app',
	);
	await expect(connectionCard(admin)).not.toContainText(LOCAL_PIXONAUT);

	await section(admin)
		.getByRole('button', { name: 'Hide developer tools' })
		.click();
	await expect(section(admin)).toBeHidden();
	await admin.reload();
	await expect(section(admin)).toBeHidden();
});

test('unlocks with the Home key, and shows a constant fixing the address', async ({
	admin,
}) => {
	await admin.goto(SETTINGS_PATH);
	await expect(section(admin)).toBeHidden();

	for (let press = 0; press < PRESSES_TO_UNLOCK; press++) {
		await admin.keyboard.press('Home');
	}

	await expect(section(admin)).toBeVisible();
	const field = section(admin).getByLabel('API base', { exact: true });
	await expect(field).not.toBeEditable();
	await expect(section(admin)).toContainText(
		'Set by the PIXONAUT_WC_API_BASE constant in wp-config.php',
	);
});
