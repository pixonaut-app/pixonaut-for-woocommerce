import { FIXTURE } from '../../fake-pixonaut/src/fixture-ids';
import { expect, test, usePlainPermalinks } from '../fixtures';
import {
	linkSide,
	openPixonautTab,
	pick,
	productPanel,
	publish,
	publishLinkedProduct,
	viewProduct,
} from '../products';

// The product screen looks the same under every theme.
test.skip(
	({ theme }) => theme !== 'storefront',
	'wp-admin does not depend on the theme',
);

/** The admin search routes the product screen calls. */
const SEARCH_ROUTE = /pixonaut(%2F|\/)v1(%2F|\/)admin(%2F|\/)search/;

test('links a simple product to a blank, placement and template', async ({
	connectedStore: page,
	fake,
	baseURL,
}) => {
	await usePlainPermalinks(page, baseURL);
	const searches: string[] = [];
	page.on('response', async (response) => {
		if (SEARCH_ROUTE.test(response.url())) {
			// A read still answering when the screen moves on has no body left.
			searches.push(await response.text().catch(() => ''));
		}
	});
	const name = `Personalised tee ${Date.now()}`;

	await publishLinkedProduct(page, name, '25');

	await openPixonautTab(page);
	const panel = productPanel(page);
	await expect(panel.locator('.pixonaut-print-size')).toHaveText(
		'280 × 360 mm',
	);
	await expect(panel.locator('.pixonaut-product-invalid')).toHaveCount(0);
	await expect(page.locator('.pixonaut-notice')).toHaveCount(0);

	// The store's integration key never reaches the browser.
	expect(searches.length).toBeGreaterThan(0);
	for (const body of searches) {
		expect(body).not.toContain(FIXTURE.store.main.integrationKey);
		expect(body).not.toContain('ik_');
	}
	const operations = (await fake.requests()).map(
		(r) => `${r.operation} ${r.status}`,
	);
	expect(operations).toEqual(
		expect.arrayContaining([
			'GET /v1/blanks 200',
			'GET /v1/blanks/{id}/placements/{placementId}/fitting-templates 200',
			'GET /v1/blanks/{id} 200',
			'GET /v1/templates/{id}/constraints 200',
			'POST /v1/templates/{id}/print-size 200',
		]),
	);

	// Purchasable: the product page offers it.
	await viewProduct(page);
	await expect(page.locator('button[name="add-to-cart"]')).toBeVisible();
});

test('saves two sides with their own print sizes and shows the printshop', async ({
	connectedStore: page,
	fake,
	baseURL,
}) => {
	await usePlainPermalinks(page, baseURL);
	await page.goto('/wp-admin/post-new.php?post_type=product');
	await page.locator('#title').fill(`Two-sided tee ${Date.now()}`);
	await page.locator('#_regular_price').fill('30');
	await openPixonautTab(page);
	const panel = productPanel(page);
	await panel.getByLabel('Personalise').check();
	await pick(page, 'pixonaut_blank', 'tee', 'Fixture tee');
	await linkSide(page, 0, 'Front');
	await panel.locator('#pixonaut_side_0_label').fill('Front');

	await panel.getByRole('button', { name: 'Add side' }).click();
	await linkSide(page, 1, 'Back');
	await panel.locator('#pixonaut_side_1_label').fill('Back');
	const back = panel.locator('.pixonaut-side[data-index="1"]');
	await back
		.getByLabel('Print size', { exact: true })
		.selectOption('Custom size');
	await back.getByLabel('Width in millimetres').fill('210');
	await back.getByLabel('Height in millimetres').fill('270');
	await back.getByLabel('Label customers see').fill('Small');
	await publish(page);

	await openPixonautTab(page);
	await expect(panel.locator('.pixonaut-product-invalid')).toHaveCount(0);
	await expect(panel.locator('.pixonaut-side')).toHaveCount(2);
	await expect(panel.locator('#pixonaut_side_1_placement')).toHaveValue(
		FIXTURE.placement.back,
	);
	await expect(
		panel
			.locator('.pixonaut-side[data-index="1"] input[name$="[label]"]')
			.last(),
	).toHaveValue('Small');
	// The fixture tee prints at its locked default printshop.
	await expect(panel.locator('.pixonaut-printshop-name')).toHaveText(
		'Fixture Print AB',
	);
	// One size check per side.
	const checks = (await fake.requests()).filter(
		(r) => r.operation === 'POST /v1/templates/{id}/print-size',
	);
	expect(checks).toHaveLength(2);
});
