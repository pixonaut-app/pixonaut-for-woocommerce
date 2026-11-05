import { FIXTURE } from '../../fake-pixonaut/src/fixture-ids';
import { expect, restUrl, test } from '../fixtures';
import { mcpSession } from '../mcp';

// Nothing here is drawn by a theme.
test.skip(
	({ theme }) => theme !== 'storefront',
	'the abilities do not depend on the theme',
);

const ABILITY = (name: string) => `pixonaut-for-woocommerce/${name}`;

/** mini-tee's 280:360 ratio, in two sizes the tee's front can print. */
const SMALL = { width_mm: 210, height_mm: 270, label: 'Small print' };
const LARGE = { width_mm: 280, height_mm: 360, label: 'Large print' };

type Setup = {
	product_id: number;
	own_setup: boolean;
	purchasable: boolean;
	valid: boolean;
	invalid_reason: string | null;
	blank_id: string | null;
	layout: string | null;
	variant: { ref: string | null } | null;
	showcase_images: { id: string; kind: string }[];
	sides: {
		key: string;
		template_id: string | null;
		template_name: string | null;
		print_sizes: { label: string }[];
	}[];
};

type Listed = Setup & {
	variations: (Setup & { variation_id: number; attributes: string })[];
};

test('an agent sets up a variable product sold in sizes over MCP, without touching its meta', async ({
	connectedStore: page,
	request,
}) => {
	const created = await request.post(restUrl('/pixonaut-e2e/v1/products'), {
		data: { name: `MCP hoodie ${Date.now()}`, variations: ['S', 'M'] },
	});
	expect(created.status()).toBe(201);
	const { id, variations } = (await created.json()) as {
		id: number;
		variations: Record<string, number>;
	};
	const mcp = await mcpSession(page);

	// The product on the tee's front, on the template's older version.
	const linked = await mcp.run<Setup>(ABILITY('set-product-setup'), {
		product_id: id,
		blank_id: FIXTURE.blank.tee,
		sides: [
			{
				key: 'front',
				label: 'Front',
				template_id: FIXTURE.template.previous,
				placement_id: FIXTURE.placement.front,
				print_sizes: [{ placement_default: true }],
			},
		],
		layout: 'inline',
	});
	expect(linked.valid, linked.invalid_reason ?? '').toBe(true);
	expect(linked.sides[0].template_name).not.toBeNull();

	// Each size variation prints at its own size, on the blank's variant of its size.
	const sizes = { S: SMALL, M: LARGE };
	const variants = {
		S: FIXTURE.variant.teeWhiteS,
		M: FIXTURE.variant.teeWhiteM,
	};
	for (const size of ['S', 'M'] as const) {
		const own = await mcp.run<Setup>(ABILITY('set-product-setup'), {
			product_id: variations[size],
			blank_id: FIXTURE.blank.tee,
			sides: [
				{
					key: 'front',
					template_id: FIXTURE.template.previous,
					placement_id: FIXTURE.placement.front,
					print_sizes: [sizes[size]],
				},
			],
			variant: variants[size],
		});
		expect(own.own_setup).toBe(true);
		expect(own.valid, own.invalid_reason ?? '').toBe(true);
		expect(own.variant?.ref).toBe(variants[size]);
	}

	// A new version of the template: everything moves, keeping its sizes.
	const moved = await mcp.run<{ moved_sides_total: number }>(
		ABILITY('move-product-template'),
		{ product_id: id, template_id: 'mini-tee', to_template_id: 'mini-tee' },
	);
	expect(moved.moved_sides_total).toBe(3);

	const showcased = await mcp.run<Setup>(ABILITY('set-product-showcase'), {
		product_id: id,
		images: [{ id: 'showcase-anna', kind: 'mockup' }],
	});
	expect(showcased.showcase_images.map((image) => image.id)).toEqual([
		'showcase-anna',
	]);

	const listed = await mcp.run<{ products: Listed[] }>(
		ABILITY('list-products'),
		{ product_id: id },
	);
	const product = listed.products[0];
	expect(product.sides[0].template_id).toBe(FIXTURE.template.live);
	expect(product.layout).toBe('inline');
	expect(product.showcase_images).toHaveLength(1);
	const bySize = Object.fromEntries(
		product.variations.map((variation) => [variation.variation_id, variation]),
	);
	for (const size of ['S', 'M'] as const) {
		const variation = bySize[variations[size]];
		expect(variation.own_setup).toBe(true);
		expect(variation.purchasable).toBe(true);
		expect(variation.sides[0].template_id).toBe(FIXTURE.template.live);
		expect(variation.sides[0].print_sizes.map((s) => s.label)).toEqual([
			sizes[size].label,
		]);
		expect(variation.variant?.ref).toBe(variants[size]);
	}
});
