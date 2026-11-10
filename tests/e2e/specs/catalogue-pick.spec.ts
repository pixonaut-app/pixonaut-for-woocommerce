import { expect, test, usePlainPermalinks } from '../fixtures';
import { createVariableProduct, PERSONALISED } from '../personalised';
import { openPixonautTab, pick, productPanel } from '../products';

// The product screen looks the same under every theme.
test.skip(
	({ theme }) => theme !== 'storefront',
	'wp-admin does not depend on the theme',
);

/** The width the design's boards are drawn at. */
const SCREEN = { width: 1280, height: 1500 };

/** The poster's sizes as the store writes them: two the printshop sells,
 *  one it does not. */
const SIZES = ['70×100 cm', '50 x 70 cm', 'A2'];

test("links a variable product by the printshop's catalogue product and pairs its variations by size", async ({
	connectedStore: page,
	request,
	fake,
	baseURL,
}) => {
	await usePlainPermalinks(page, baseURL);
	const id = await createVariableProduct(
		request,
		`Name poster ${Date.now()}`,
		PERSONALISED,
		SIZES,
	);
	await fake.control.post('/__fake/catalogue/imports', {
		data: { held: true },
	});
	await page.setViewportSize(SCREEN);
	await page.goto(`/wp-admin/post.php?post=${id}&action=edit`);
	await openPixonautTab(page);
	const panel = productPanel(page);

	const source = panel.getByLabel('Choose from');
	await expect(source).toHaveValue('', {
		timeout: 10_000,
	});
	await source.selectOption({ label: 'Gelato’s catalogue' });
	await pick(page, 'pixonaut_blank', 'poster', 'Classic poster');

	const importing = panel.locator('.pixonaut-blank-importing');
	await expect(importing).toContainText(
		'Importing Classic poster from Gelato into Pixonaut…',
	);
	const blankId = await panel.locator('#pixonaut_blank').inputValue();
	expect(blankId).toMatch(/^blank_gelato_poster_/);

	const finished = await fake.control.post('/__fake/blanks/finish-import', {
		data: { blank: blankId },
	});
	expect(finished.ok()).toBe(true);

	// Read again by itself once the import is done.
	const pairing = panel.locator('.pixonaut-variant-pairing');
	await expect(pairing).toBeVisible({ timeout: 20_000 });
	await expect(importing).toHaveCount(0);
	await expect(pairing).toContainText(
		'2 of 3 variations are paired with a Gelato variant.',
	);
	const unpaired = pairing.locator('.pixonaut-variant-pairing__row').first();
	await expect(unpaired).toContainText('A2');
	await expect(unpaired).toContainText('No Gelato variant found: pick one.');
	await expect(pairing).toContainText('700 × 1000 mm');
	await expect(pairing).toContainText('500 × 700 mm');
	await page.locator('#woocommerce-product-data').screenshot({
		path: test.info().outputPath('catalogue-pick-paired.png'),
	});

	// Picked again, the product reuses the blank it was imported as.
	await pick(page, 'pixonaut_blank', 'poster', 'Classic poster');
	await expect(pairing).toBeVisible();
	expect(await panel.locator('#pixonaut_blank').inputValue()).toBe(blankId);
	const imports = (await fake.requests()).filter(
		(r) =>
			r.operation === 'POST /v1/printshop-catalogue-products/{productId}/blank',
	);
	expect(imports).toHaveLength(1);
});
