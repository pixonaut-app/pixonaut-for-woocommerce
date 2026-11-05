import { FIXTURE } from '../../fake-pixonaut/src/fixture-ids';
import { expect, restUrl, test } from '../fixtures';
import { mcpSession } from '../mcp';
import { runJobs } from '../orders';
import { PERSONALISED } from '../personalised';

// Nothing here is drawn by a theme.
test.skip(
	({ theme }) => theme !== 'storefront',
	'the abilities do not depend on the theme',
);

const ABILITY = (name: string) => `pixonaut-for-woocommerce/${name}`;

/** The job that draws a product's picture. */
const PICTURE_HOOK = 'pixonaut_wc_catalogue_picture';

type Settings = {
	show_field_values: boolean;
	proof_deadline_days: number;
	default_layout: string;
};

type Listed = { products: { picture: string; image_id?: number }[] };

test('an agent changes the Pixonaut settings over MCP, and the settings page shows them', async ({
	connectedStore: page,
}) => {
	const mcp = await mcpSession(page);
	const before = await mcp.run<Settings>(ABILITY('get-plugin-settings'), {});

	try {
		const changed = await mcp.run<Settings>(ABILITY('update-plugin-settings'), {
			show_field_values: !before.show_field_values,
			proof_deadline_days: 9,
		});
		expect(changed.show_field_values).toBe(!before.show_field_values);
		expect(changed.proof_deadline_days).toBe(9);
		expect(changed.default_layout).toBe(before.default_layout);

		await page.goto('/wp-admin/admin.php?page=pixonaut&tab=approval');
		await expect(
			page.locator('input[name="pixonaut_wc_settings[proof_deadline_days]"]'),
		).toHaveValue('9');
	} finally {
		await mcp.run(ABILITY('update-plugin-settings'), {
			show_field_values: before.show_field_values,
			proof_deadline_days: before.proof_deadline_days,
		});
	}
});

test('an agent redraws a product’s Pixonaut picture over MCP', async ({
	connectedStore: page,
	request,
}) => {
	const created = await request.post(restUrl('/pixonaut-e2e/v1/products'), {
		data: {
			name: `MCP pictured ${Date.now()}`,
			pixonaut: PERSONALISED,
			variant: FIXTURE.variant.teeWhiteS,
		},
	});
	expect(created.status()).toBe(201);
	const { id } = (await created.json()) as { id: number };
	await runJobs(request, { hook: PICTURE_HOOK });
	const mcp = await mcpSession(page);
	const image = async () =>
		(
			(await (
				await request.get(restUrl(`/wc/store/v1/products/${id}`))
			).json()) as { images: { id: number }[] }
		).images[0]?.id;
	const drawn = await image();
	expect(drawn).toBeDefined();

	const redrawn = await mcp.run<{
		products: { product_id: number; state: string }[];
	}>(ABILITY('regenerate-product-pictures'), { product_ids: [id] });
	expect(redrawn.products).toHaveLength(1);
	expect(redrawn.products[0].product_id).toBe(id);
	// The runners start at once, and may have drawn it by the time it answers.
	expect(['drawing', 'drawn']).toContain(redrawn.products[0].state);
	await runJobs(request, { hook: PICTURE_HOOK });

	const listed = await mcp.run<Listed>(ABILITY('list-products'), {
		product_id: id,
	});
	expect(listed.products[0].picture).toBe('drawn');
	// A new picture took the place of the one drawn before.
	expect(await image()).not.toBe(drawn);
});
