import { expect, test, usePlainPermalinks } from '../fixtures';
import {
	linkSide,
	openPixonautTab,
	pick,
	productPanel,
	publish,
} from '../products';

// The product screen looks the same under every theme.
test.skip(
	({ theme }) => theme !== 'storefront',
	'wp-admin does not depend on the theme',
);

test('a side follows its template until it is stuck to one version', async ({
	connectedStore: page,
	baseURL,
}, testInfo) => {
	await usePlainPermalinks(page, baseURL);
	await page.goto('/wp-admin/post-new.php?post_type=product');
	await page.locator('#title').fill(`Pinned tee ${Date.now()}`);
	await page.locator('#_regular_price').fill('25');
	await openPixonautTab(page);
	const panel = productPanel(page);
	await panel.getByLabel('Personalise').check();
	await pick(page, 'pixonaut_blank', 'tee', 'Fixture tee');
	await linkSide(page, 0, 'Front');

	const side = panel.locator('.pixonaut-side[data-index="0"]');
	const stick = side.getByLabel('Stick to one version');
	const picker = side.locator('select.pixonaut-side-version');
	// A template just chosen is followed: the picker waits for the box.
	await expect(stick).not.toBeChecked();
	await expect(picker).toBeHidden();

	await stick.check();
	await expect(picker).toBeVisible();
	await expect(picker.locator('option').first()).toHaveText(
		/^Version \d+ \(current\)$/,
	);
	const current = await picker.inputValue();
	await publish(page);

	await openPixonautTab(page);
	const saved = productPanel(page).locator('.pixonaut-side[data-index="0"]');
	await expect(saved.getByLabel('Stick to one version')).toBeChecked();
	await expect(saved.locator('select.pixonaut-side-version')).toHaveValue(
		current,
	);
	await saved.screenshot({ path: testInfo.outputPath('pinned-side.png') });
});
