import type { APIRequestContext, Locator, Page } from '@playwright/test';
import { expect, restUrl, test } from '../fixtures';
import { createVariableProduct, PERSONALISED } from '../personalised';
import { openPixonautTab, productPanel, publish } from '../products';

// The product screen looks the same under every theme.
test.skip(
	({ theme }) => theme !== 'storefront',
	'wp-admin does not depend on the theme',
);

/** mini-tee's artwork: a print of it keeps this shape. */
const MINI_TEE = { w: 280, h: 360 };

/** mini-tee on no blank, sold as a poster at its own ratio. */
const TEMPLATE_ONLY = {
	...PERSONALISED,
	blank: null,
	sides: [
		{
			key: 'design',
			label: 'Mini tee',
			placement: null,
			template: PERSONALISED.sides[0]?.template,
			printSizes: [
				{ widthMm: MINI_TEE.w, heightMm: MINI_TEE.h, label: 'Poster' },
			],
		},
	],
};

/** A variation of a size, in the Variations tab, opened. */
async function variation(page: Page, size: string): Promise<Locator> {
	const row = page.locator('.woocommerce_variation', {
		has: page.locator(
			`select[name^="attribute_size"] option[value="${size}"]:checked`,
		),
	});
	await row.locator('h3 strong').first().click();
	return row.locator('.pixonaut-variation-panel');
}

/** Whether each of a variable product's variations can be bought, by size. */
async function purchasable(
	request: APIRequestContext,
	id: number,
): Promise<Record<string, boolean>> {
	const product = await request.get(restUrl(`/wc/store/v1/products/${id}`));
	expect(product.ok()).toBe(true);
	const { variations } = (await product.json()) as {
		variations: { id: number; attributes: { value: string }[] }[];
	};
	const sold: Record<string, boolean> = {};
	for (const { id: variationId, attributes } of variations) {
		const read = await request.get(
			restUrl(`/wc/store/v1/products/${variationId}`),
		);
		sold[attributes[0]?.value ?? ''] = (await read.json()).is_purchasable;
	}
	return sold;
}

test('a variable poster prints each size at its value’s print size, with none of its own', async ({
	connectedStore: page,
	request,
}) => {
	// Two product saves, each read back, and the variations opened.
	test.slow();
	const id = await createVariableProduct(
		request,
		`Sized poster ${Date.now()}`,
		TEMPLATE_ONLY,
		['S', 'M'],
	);
	await page.goto(`/wp-admin/post.php?post=${id}&action=edit`);
	await openPixonautTab(page);
	const side = productPanel(page).locator('.pixonaut-side').first();

	// The product's own size goes; each size gets one by its value.
	await side.locator('.pixonaut-remove-size').click();
	await expect(side.locator('.pixonaut-size')).toHaveCount(0);
	await side.getByLabel('Print size by attribute').selectOption('size');
	await side.getByLabel('S: width in millimetres').fill('140');
	await side.getByLabel('S: height in millimetres').fill('180');
	await side.getByLabel('M: width in millimetres').fill(String(MINI_TEE.w));
	await side.getByLabel('M: height in millimetres').fill(String(MINI_TEE.h));
	await side.getByLabel('M: label customers see').fill('Large');
	await publish(page);

	await expect(page.locator('.pixonaut-product-invalid')).toHaveCount(0);
	await expect(page.getByText('Customers cannot buy')).toHaveCount(0);
	await openPixonautTab(page);
	await expect(side.getByLabel('S: width in millimetres')).toHaveValue('140');
	await expect(side.getByLabel('M: label customers see')).toHaveValue('Large');
	await expect(side.getByLabel('S: label customers see')).toHaveValue('');
	expect(await purchasable(request, id)).toEqual({ S: true, M: true });

	// Each variation says what it prints at, its own size left empty.
	await page.locator('.variations_tab a').click();
	const small = await variation(page, 'S');
	await expect(
		small.locator('.pixonaut-variation-size-kind option[value="product"]'),
	).toHaveText('As its size (S)');
	const medium = await variation(page, 'M');
	await expect(
		medium.locator('.pixonaut-variation-size-kind option[value="product"]'),
	).toHaveText('As its size (Large)');

	// Without M's size, M cannot be bought, and the product says why.
	await openPixonautTab(page);
	await side.getByLabel('M: width in millimetres').fill('');
	await side.getByLabel('M: height in millimetres').fill('');
	await publish(page);

	await expect(page.locator('.pixonaut-product-invalid')).toContainText(
		'Some variations have no print size.',
	);
	expect(await purchasable(request, id)).toEqual({ S: true, M: false });
});
