import { FIXTURE } from '../../fake-pixonaut/src/fixture-ids';
import { expect, restUrl, test } from '../fixtures';
import { mcpSession } from '../mcp';
import { PERSONALISED } from '../personalised';

const ABILITY = (name: string) => `pixonaut-for-woocommerce/${name}`;

/** Read by the e2e support mu-plugin: the store's `attribute_options`
 *  setting for this browser context. */
const ATTRIBUTE_OPTIONS_COOKIE = 'pixonaut_e2e_attribute_options';

/** Most digits of the clock a unique attribute slug takes: slugs are short. */
const SLUG_CLOCK_DIGITS = 8;

/** The store's frame colours, each with the tee's Color value it stands for,
 *  the fake's fill of that value, and the view the designer shows it in. */
const FRAMES = [
	{
		name: 'Vit',
		slug: 'vit',
		value: 'White',
		fill: 'rgb(255, 255, 255)',
		variant: FIXTURE.variant.teeWhiteS,
		view: 'Front',
	},
	{
		name: 'Svart',
		slug: 'svart',
		value: 'Black',
		fill: 'rgb(0, 0, 0)',
		variant: FIXTURE.variant.teeBlackS,
		view: 'Front, dark',
	},
] as const;

type Row = {
	option: string | null;
	values: { slug: string; stands_for: string | null }[];
	options: Record<string, string[]>;
};

test('an attribute linked to the blank’s colour over MCP shows its values as the colours’ swatches, and each picks its variant', async ({
	connectedStore: page,
	request,
	baseURL,
}) => {
	const created = await request.post(restUrl('/pixonaut-e2e/v1/products'), {
		data: {
			name: `Framed ${Date.now()}`,
			pixonaut: PERSONALISED,
			variations: FRAMES.map((frame) => frame.name),
			variants: Object.fromEntries(
				FRAMES.map((frame) => [frame.name, frame.variant]),
			),
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
		{ fields: { name: 'Ramfärg', slug } },
	);
	try {
		for (const frame of FRAMES) {
			await mcp.run(ABILITY('create-attribute-term'), {
				attribute_id: attribute.id,
				fields: { name: frame.name, slug: frame.slug },
			});
		}
		await mcp.run(ABILITY('move-product-attribute'), {
			from: 'size',
			to: `pa_${slug}`,
			product_ids: [id],
		});

		const before = await mcp.run<{ attributes: Row[] }>(
			ABILITY('get-attribute-blank-options'),
			{ attribute_id: attribute.id },
		);
		expect(before.attributes[0].option).toBeNull();
		expect(before.attributes[0].options.Color).toEqual(
			expect.arrayContaining(FRAMES.map((frame) => frame.value)),
		);

		const linked = await mcp.run<Row>(ABILITY('set-attribute-blank-option'), {
			attribute_id: attribute.id,
			option: 'Color',
			values: FRAMES.map((frame) => ({ slug: frame.slug, value: frame.value })),
		});
		expect(linked.option).toBe('Color');
		for (const frame of FRAMES) {
			expect(
				linked.values.find((value) => value.slug === frame.slug)?.stands_for,
			).toBe(frame.value);
		}

		await page.context().addCookies([
			{
				name: ATTRIBUTE_OPTIONS_COOKIE,
				value: 'swatches',
				url: baseURL ?? '',
			},
		]);
		await page.goto(permalink);
		const swatches = page
			.locator('#pixonaut-form-front')
			.getByRole('radiogroup', { name: /Ramfärg/ });
		await expect(swatches).toBeVisible();
		for (const frame of FRAMES) {
			await expect(
				swatches
					.getByRole('radio', { name: frame.name })
					.locator('.pixonaut-swatches__look'),
			).toHaveCSS('background-color', frame.fill);
		}
		// The page opens on the first value; each value in turn, last first.
		for (const frame of [...FRAMES].reverse()) {
			const swatch = swatches.getByRole('radio', { name: frame.name });
			await swatch.click();
			await expect(swatch).toHaveAttribute('aria-checked', 'true');
			// The designer draws the variant the value picks.
			await expect(
				page.getByRole('button', { name: frame.view, exact: true }),
			).toHaveAttribute('aria-pressed', 'true');
		}
		// The arrows move the choice, as in any radio group.
		await page.keyboard.press('ArrowRight');
		await expect(
			swatches.getByRole('radio', { name: FRAMES[1].name }),
		).toHaveAttribute('aria-checked', 'true');
		await expect(
			page.getByRole('button', { name: FRAMES[1].view, exact: true }),
		).toHaveAttribute('aria-pressed', 'true');
	} finally {
		await mcp.run(ABILITY('delete-product-attribute'), {
			id: attribute.id,
			force: true,
		});
	}
});
