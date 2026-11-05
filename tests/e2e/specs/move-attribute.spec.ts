import { FIXTURE } from '../../fake-pixonaut/src/fixture-ids';
import { expect, restUrl, test } from '../fixtures';
import { mcpSession } from '../mcp';
import { addToCartButton, PERSONALISED } from '../personalised';

// Nothing here is drawn differently by a theme: the classic select is enough.
test.skip(
	({ theme }) => theme !== 'storefront',
	'the move does not depend on the theme',
);

const ABILITY = (name: string) => `pixonaut-for-woocommerce/${name}`;

const SMALL = { widthMm: 210, heightMm: 270, label: 'Small' };

/** Each size's price, told apart by its whole units. */
const PRICES = { Small: '23.00', Large: '47.00' };

/** Most digits of the clock a unique attribute slug takes: slugs are short. */
const SLUG_CLOCK_DIGITS = 8;

test('an agent moves a product to a global attribute over MCP and its sizes and old links still work', async ({
	connectedStore: page,
	request,
}) => {
	const created = await request.post(restUrl('/pixonaut-e2e/v1/products'), {
		data: {
			name: `Moved sign ${Date.now()}`,
			pixonaut: {
				...PERSONALISED,
				v: 6,
				sides: PERSONALISED.sides.map((side) => ({
					...side,
					formGroups: {
						groups: [
							{
								id: 'wc-size',
								title: 'Your size',
								items: [{ attribute: 'size' }],
							},
						],
					},
				})),
			},
			variations: Object.keys(PRICES),
			prices: PRICES,
			overrides: {
				Small: {
					...PERSONALISED,
					sides: [{ ...PERSONALISED.sides[0], printSizes: [SMALL] }],
				},
			},
			variant: FIXTURE.variant.teeWhiteS,
		},
	});
	expect(created.status()).toBe(201);
	const { id, permalink, variations } = (await created.json()) as {
		id: number;
		permalink: string;
		variations: Record<string, number>;
	};
	const mcp = await mcpSession(page);

	const slug = `e2e${String(Date.now()).slice(-SLUG_CLOCK_DIGITS)}`;
	const taxonomy = `pa_${slug}`;
	const attribute = await mcp.run<{ id: number }>(
		ABILITY('create-product-attribute'),
		{ fields: { name: 'Size', slug } },
	);
	for (const name of Object.keys(PRICES)) {
		await mcp.run(ABILITY('create-attribute-term'), {
			attribute_id: attribute.id,
			fields: { name },
		});
	}

	type Answer = {
		products: { product_id: number; status: string; variations: number }[];
	};
	const input = { from: 'size', to: taxonomy, product_ids: [id] };
	const dry = await mcp.run<Answer>(ABILITY('move-product-attribute'), {
		...input,
		dry_run: true,
	});
	expect(dry.products[0].status).toBe('would_move');
	const moved = await mcp.run<Answer>(ABILITY('move-product-attribute'), input);
	expect(moved.products[0]).toMatchObject({ status: 'moved', variations: 2 });

	const product = await mcp.run<{
		attributes: { slug: string; options: string[]; variation: boolean }[];
	}>(ABILITY('get-product'), { id });
	expect(product.attributes[0].slug).toBe(taxonomy);
	expect(product.attributes[0].variation).toBe(true);

	const listed = await mcp.run<{
		products: { valid: boolean; variations: { valid: boolean }[] }[];
	}>(ABILITY('list-products'), { product_id: id });
	expect(listed.products[0].valid).toBe(true);
	for (const variation of listed.products[0].variations) {
		expect(variation.valid).toBe(true);
	}

	// A link naming the old attribute selects the same size under the new one.
	const old = new URL(permalink);
	old.searchParams.set('attribute_size', 'Large');
	await page.goto(old.toString());
	expect(new URL(page.url()).searchParams.get('attribute_size')).toBeNull();
	const field = page.locator(`select[name="attribute_${taxonomy}"]`);
	await expect(field).toHaveValue('large');
	await expect(page.getByText('Your size').first()).toBeVisible();

	// Each size selects its own variation, as it did.
	for (const [size, value] of [
		['Small', 'small'],
		['Large', 'large'],
	]) {
		await field.selectOption(value);
		await expect(addToCartButton(page)).not.toHaveClass(/\bdisabled\b/);
		await expect(page.locator('input[name="variation_id"]')).toHaveValue(
			String(variations[size]),
		);
	}

	await mcp.run(ABILITY('delete-product-attribute'), {
		id: attribute.id,
		force: true,
	});
});
