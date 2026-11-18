import { FIXTURE } from '../../fake-pixonaut/src/fixture-ids';
import { expect, restUrl, test, usePlainPermalinks } from '../fixtures';
import { mcpSession } from '../mcp';
import { PERSONALISED } from '../personalised';

const ABILITY = (name: string) => `pixonaut-for-woocommerce/${name}`;

/** Most digits of the clock a unique attribute slug takes: slugs are short. */
const SLUG_CLOCK_DIGITS = 7;

/** The store's colours and sizes, each with the tee's value it stands for. */
const ATTRIBUTES = [
	{
		name: 'Färg',
		prefix: 'farg',
		option: 'Color',
		values: [
			{ name: 'Vit', slug: 'vit', value: 'White' },
			{ name: 'Svart', slug: 'svart', value: 'Black' },
		],
	},
	{
		name: 'Storlek',
		prefix: 'stl',
		option: 'Size',
		values: [
			{ name: 'S', slug: 's', value: 'S' },
			{ name: 'M', slug: 'm', value: 'M' },
		],
	},
] as const;

type Plan = {
	entries: { blank_id: string }[];
	attributes: { attribute: string; option: string | null }[];
};

test('a variable product’s variations are previewed and generated from its blank options in the Variations tab, and the plan reads over MCP', async ({
	connectedStore: page,
	request,
	baseURL,
}) => {
	// The section posts to the REST API, which the tests site serves at its plain address alone.
	await usePlainPermalinks(page, baseURL ?? '');
	const created = await request.post(restUrl('/pixonaut-e2e/v1/products'), {
		data: {
			name: `Generated tee ${Date.now()}`,
			pixonaut: PERSONALISED,
			variations: ['Old'],
			variant: FIXTURE.variant.teeWhiteS,
		},
	});
	expect(created.status()).toBe(201);
	const { id, variations } = (await created.json()) as {
		id: number;
		variations: Record<string, number>;
	};

	const mcp = await mcpSession(page);
	const clock = String(Date.now()).slice(-SLUG_CLOCK_DIGITS);
	const made: number[] = [];
	try {
		const offered = [];
		for (const attribute of ATTRIBUTES) {
			const row = await mcp.run<{ id: number }>(
				ABILITY('create-product-attribute'),
				{
					fields: {
						name: `${attribute.name} ${clock}`,
						slug: `${attribute.prefix}${clock}`,
					},
				},
			);
			made.push(row.id);
			for (const value of attribute.values) {
				await mcp.run(ABILITY('create-attribute-term'), {
					attribute_id: row.id,
					fields: { name: value.name, slug: value.slug },
				});
			}
			offered.push({
				id: row.id,
				options: attribute.values.map((value) => value.name),
				variation: true,
				visible: true,
			});
		}
		// The old size variation is retired by hand: WooCommerce would sell it for any value of the new attributes.
		await mcp.run(ABILITY('update-product-variation'), {
			product_id: id,
			id: variations.Old,
			fields: { status: 'private' },
		});
		// The old custom size gives way to the global colour and size.
		await mcp.run(ABILITY('update-product'), {
			id,
			fields: { attributes: offered },
		});
		for (const [index, attribute] of ATTRIBUTES.entries()) {
			await mcp.run(ABILITY('set-attribute-blank-option'), {
				attribute_id: made[index],
				option: attribute.option,
				values: attribute.values.map((value) => ({
					slug: value.slug,
					value: value.value,
				})),
			});
		}

		await page.goto(`/wp-admin/post.php?post=${id}&action=edit`);
		await page.locator('.variations_options a').click();
		const section = page.locator('.pixonaut-variation-plan');
		await expect(section).toBeVisible();
		await expect(
			section.locator('.pixonaut-variation-plan__blank'),
		).toHaveValue(FIXTURE.blank.tee);

		await section.getByRole('button', { name: 'Preview' }).click();
		await expect(
			section.locator('.pixonaut-variation-plan__status'),
		).toHaveText('Generating would add 4, change 0 and disable 0 variations.');
		const previewed = page.locator('.pixonaut-variation-plan');
		await expect(previewed.getByText('To add (4)')).toBeVisible();
		await expect(previewed.getByText(/Color: White, Size: M/)).toBeVisible();

		await previewed
			.getByRole('button', { name: 'Generate from the blank' })
			.click();
		const generated = page.locator('.pixonaut-variation-plan');
		await expect(
			generated.locator('.pixonaut-variation-plan__status'),
		).toHaveText('Added 4, changed 0 and disabled 0 variations.');
		// WooCommerce's panel loads the variations again: the four made and the one disabled by hand.
		await expect(page.locator('.woocommerce_variation')).toHaveCount(5);

		const plan = await mcp.run<Plan>(ABILITY('get-product-variation-plan'), {
			product_id: id,
		});
		expect(plan.entries.map((entry) => entry.blank_id)).toEqual([
			FIXTURE.blank.tee,
		]);
		expect(plan.attributes.map((row) => row.option)).toEqual(['Color', 'Size']);

		const again = await mcp.run<{ add: unknown[]; kept: number }>(
			ABILITY('generate-product-variations'),
			{ product_id: id },
		);
		expect(again.add).toEqual([]);
		expect(again.kept).toBe(5);
	} finally {
		for (const attribute of made) {
			await mcp.run(ABILITY('delete-product-attribute'), {
				id: attribute,
				force: true,
			});
		}
	}
});
