import { expect, restUrl, test } from '../fixtures';
import { mcpSession } from '../mcp';
import { PERSONALISED } from '../personalised';

const ABILITY = (name: string) => `pixonaut-for-woocommerce/${name}`;

/** Read by the e2e support mu-plugin: the store's `attribute_options`
 *  setting for this browser context. */
const ATTRIBUTE_OPTIONS_COOKIE = 'pixonaut_e2e_attribute_options';

/** Most digits of the clock a unique attribute slug takes: slugs are short. */
const SLUG_CLOCK_DIGITS = 8;

/** A poster sold bare, with a hanger or framed, each priced apart. */
const FRAMINGS = [
	{ name: 'Ingen', slug: 'ingen', price: '100' },
	{ name: 'Hängare', slug: 'hangare', price: '150' },
	{ name: 'Ram', slug: 'ram', price: '400' },
] as const;

test('an attribute set as an add-on over MCP shows what each value adds to the base product beside its swatch', async ({
	connectedStore: page,
	request,
	baseURL,
}) => {
	const created = await request.post(restUrl('/pixonaut-e2e/v1/products'), {
		data: {
			name: `Framed prices ${Date.now()}`,
			pixonaut: PERSONALISED,
			variations: FRAMINGS.map((framing) => framing.name),
			prices: Object.fromEntries(
				FRAMINGS.map((framing) => [framing.name, framing.price]),
			),
			default: FRAMINGS[0].name,
		},
	});
	expect(created.status()).toBe(201);
	const { id, permalink } = (await created.json()) as {
		id: number;
		permalink: string;
	};

	const mcp = await mcpSession(page);
	const slug = `e2e${String(Date.now()).slice(-SLUG_CLOCK_DIGITS)}`;
	const attribute = await mcp.run<{ id: number }>(
		ABILITY('create-product-attribute'),
		{ fields: { name: 'Inramning', slug } },
	);
	try {
		for (const framing of FRAMINGS) {
			await mcp.run(ABILITY('create-attribute-term'), {
				attribute_id: attribute.id,
				fields: { name: framing.name, slug: framing.slug },
			});
		}
		await mcp.run(ABILITY('move-product-attribute'), {
			from: 'size',
			to: `pa_${slug}`,
			product_ids: [id],
		});

		const set = await mcp.run<{ choice_price: string }>(
			ABILITY('set-attribute-choice-price'),
			{ attribute_id: attribute.id, choice_price: 'add_on' },
		);
		expect(set.choice_price).toBe('add_on');
		const read = await mcp.run<{
			attributes: { attribute_id: number; choice_price: string }[];
		}>(ABILITY('get-attribute-choice-prices'), {});
		expect(
			read.attributes.find((row) => row.attribute_id === attribute.id)
				?.choice_price,
		).toBe('add_on');

		await page.context().addCookies([
			{
				name: ATTRIBUTE_OPTIONS_COOKIE,
				value: 'swatches',
				url: baseURL ?? '',
			},
		]);

		// The page prints the amounts with its swatches, before any script.
		const printed = await page.request.get(permalink);
		const html = await printed.text();
		expect(html).toContain('pixonaut-swatches__price');

		await page.goto(permalink);
		const swatches = page
			.locator('#pixonaut-form-front')
			.getByRole('radiogroup', { name: /Inramning/ });
		await expect(swatches).toBeVisible();
		const price = (name: string) =>
			swatches
				.getByRole('radio', { name: new RegExp(name) })
				.locator('.pixonaut-swatches__price');

		// The cheapest framing is the base product: it shows no price.
		await expect(price('Ingen')).toHaveCount(0);
		await expect(price('Hängare')).toHaveText(/^\+.*50/);
		await expect(price('Ram')).toHaveText(/^\+.*300/);

		// The amounts stay what each adds to the base, whatever is chosen.
		await swatches.getByRole('radio', { name: /Ram/ }).click();
		await expect(swatches.getByRole('radio', { name: /Ram/ })).toHaveAttribute(
			'aria-checked',
			'true',
		);
		await expect(price('Hängare')).toHaveText(/^\+.*50/);
		await expect(price('Ingen')).toHaveCount(0);
	} finally {
		await mcp.run(ABILITY('delete-product-attribute'), {
			id: attribute.id,
			force: true,
		});
	}
});
