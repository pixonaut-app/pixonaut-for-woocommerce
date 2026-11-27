import type { Page } from '@playwright/test';
import { expect, restUrl, test } from '../fixtures';
import { mcpSession } from '../mcp';
import { createProduct, PERSONALISED } from '../personalised';

// What is checked here is what the page mounts with, not how a theme draws it.
test.skip(
	({ theme }) => theme !== 'storefront',
	'the mount options do not depend on the theme',
);

const SET_VALUES = 'pixonaut-for-woocommerce/set-product-values';

/** The fixture tee template's text layer, as the product's own value. */
const FARMOR = { slogan: { content: 'Världens bästa farmor' } };

type Values = {
	sides: { key: string; value_set_id: string | null }[];
};

/** The side's mount options the product page printed. */
async function frontMount(page: Page): Promise<Record<string, unknown>> {
	const printed = await page
		.locator('[data-pixonaut-mounts]')
		.first()
		.getAttribute('data-pixonaut-mounts');
	const mounts = JSON.parse(printed ?? '{}') as Record<
		string,
		Record<string, unknown>
	>;
	expect(mounts.front, 'the front mounts').toBeDefined();
	return mounts.front ?? {};
}

test('two products on one template: the one with values of its own opens its designer on their value set', async ({
	connectedStore: page,
	request,
}) => {
	const created = await request.post(restUrl('/pixonaut-e2e/v1/products'), {
		data: { name: `Farmor tee ${Date.now()}`, pixonaut: PERSONALISED },
	});
	expect(created.status()).toBe(201);
	const { id: productId, permalink: own } = (await created.json()) as {
		id: number;
		permalink: string;
	};
	const plain = await createProduct(
		request,
		`Plain tee ${Date.now()}`,
		PERSONALISED,
	);

	const mcp = await mcpSession(page);
	const set = await mcp.run<Values>(SET_VALUES, {
		product_id: productId,
		sides: [{ key: 'front', values: FARMOR }],
	});
	const valueSet = set.sides.find((side) => side.key === 'front')?.value_set_id;
	expect(valueSet).toMatch(/^vs_[0-9a-f]{24}$/);

	await page.goto(own);
	expect((await frontMount(page)).valueSets).toEqual({ 'mini-tee': valueSet });
	// The designer starts from the set's values.
	await expect(
		page.locator('#pixonaut-form-front').getByRole('textbox').first(),
	).toHaveValue(FARMOR.slogan.content);

	await page.goto(plain);
	expect(await frontMount(page)).not.toHaveProperty('valueSets');
});
