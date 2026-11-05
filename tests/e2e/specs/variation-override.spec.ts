import { expect, test } from '../fixtures';
import { createVariableProduct, PERSONALISED } from '../personalised';
import { openPixonautTab, productPanel } from '../products';

// The product screen looks the same under every theme.
test.skip(
	({ theme }) => theme !== 'storefront',
	'wp-admin does not depend on the theme',
);

test('saves a variation that overrides its product with its own print size', async ({
	connectedStore: page,
	request,
	fake,
}) => {
	const id = await createVariableProduct(
		request,
		`Personalised hoodie ${Date.now()}`,
		PERSONALISED,
		['S', 'L'],
	);
	const openLarge = async () => {
		await page.goto(`/wp-admin/post.php?post=${id}&action=edit`);
		await page.locator('.variations_tab a').click();
		const large = page.locator('.woocommerce_variation', {
			has: page.locator(
				'select[name^="attribute_size"] option[value="L"]:checked',
			),
		});
		await large.locator('h3 strong').first().click();
		return large.locator('.pixonaut-variation-panel');
	};

	// A variable product is linked in its Pixonaut tab.
	await page.goto(`/wp-admin/post.php?post=${id}&action=edit`);
	await openPixonautTab(page);
	await expect(productPanel(page).getByLabel('Personalise')).toBeChecked();

	const panel = await openLarge();
	const override = panel.getByLabel('Override for this variation');
	await expect(override).not.toBeChecked();
	const fields = panel.locator('.pixonaut-variation-fields');
	await expect(fields).toBeHidden();

	await override.check();
	await expect(fields).toBeVisible();
	// Filled in with what the variation inherits.
	await expect(
		fields.locator('select.pixonaut-blank option:checked'),
	).toHaveText('Fixture tee');
	const front = fields.locator('.pixonaut-side[data-index="0"]');
	await front
		.getByLabel('Print size', { exact: true })
		.selectOption('Custom size');
	await front.getByLabel('Width in millimetres').fill('210');
	await front.getByLabel('Height in millimetres').fill('270');
	await front.getByLabel('Label customers see').fill('Small');
	const saved = page.waitForResponse(
		(response) =>
			response.url().includes('admin-ajax.php') &&
			(response.request().postData() ?? '').includes(
				'woocommerce_save_variations',
			),
	);
	await page.getByRole('button', { name: 'Save changes' }).click();
	expect((await saved).ok()).toBe(true);

	const reloaded = await openLarge();
	await expect(
		reloaded.getByLabel('Override for this variation'),
	).toBeChecked();
	await expect(reloaded.locator('.pixonaut-product-invalid')).toHaveCount(0);
	await expect(reloaded.getByLabel('Label customers see').first()).toHaveValue(
		'Small',
	);
	const checks = (await fake.requests()).filter(
		(r) => r.operation === 'POST /v1/templates/{id}/print-size',
	);
	expect(checks).toHaveLength(1);
});
