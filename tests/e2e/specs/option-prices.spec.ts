import type { Page } from '@playwright/test';
import { expect, restUrl, test, usePlainPermalinks } from '../fixtures';
import { mcpSession } from '../mcp';
import { addToCartButton, PERSONALISED } from '../personalised';

const ABILITY = (name: string) => `pixonaut-for-woocommerce/${name}`;

/** Read by the e2e support mu-plugin: the store's `attribute_options`
 *  setting for this browser context. */
const ATTRIBUTE_OPTIONS_COOKIE = 'pixonaut_e2e_attribute_options';

/** Most digits of the clock a unique attribute slug takes: slugs are short. */
const SLUG_CLOCK_DIGITS = 8;

/** A poster in two sizes, bare or framed; the frame adds 200 to either. */
const SIZES = { '30x40': '499', '50x70': '699' } as const;
const FRAMES = { Ingen: '0', Ram: '200' } as const;

/** The block form's element of one attribute. */
const BLOCK_ATTRIBUTE =
	'.wp-block-woocommerce-add-to-cart-with-options-variation-selector-attribute';

/** The words each option of the attribute posted as `field` shows: a
 *  classic select's options, or the block form's chips. */
async function optionTexts(page: Page, field: string): Promise<string[]> {
	const select = page.locator(`select[name="${field}"]`);
	if ((await select.count()) > 0) {
		return (
			await select.locator('option:not([value=""])').allTextContents()
		).map((text) => text.trim());
	}
	return (
		await page
			.locator(BLOCK_ATTRIBUTE)
			.filter({ has: page.locator(`input[name="${field}"]`) })
			.locator('.wc-block-product-filter-chips__text')
			.allTextContents()
	).map((text) => text.trim());
}

/** Chooses `value` of the attribute posted as `field`, in either form. */
async function choose(page: Page, field: string, value: string): Promise<void> {
	const select = page.locator(`select[name="${field}"]`);
	if ((await select.count()) > 0) {
		await select.selectOption(value);
		return;
	}
	await page
		.locator(BLOCK_ATTRIBUTE)
		.filter({ has: page.locator(`input[name="${field}"]`) })
		.locator(`button[role="radio"][value="${value}"]`)
		.click();
}

test("each size shows its price with the chosen frame, in the theme's own field and beside its swatch", async ({
	connectedStore: admin,
	customer: page,
	request,
	baseURL,
}) => {
	const created = await request.post(restUrl('/pixonaut-e2e/v1/products'), {
		data: {
			name: `Sized prices ${Date.now()}`,
			pixonaut: PERSONALISED,
			variations: Object.keys(SIZES),
			finishes: Object.keys(FRAMES),
			prices: SIZES,
			finish_prices: FRAMES,
		},
	});
	expect(created.status()).toBe(201);
	const { id } = (await created.json()) as { id: number };
	// The cart's form posts to the product's address, which the tests site
	// serves only as a plain one. The guest shops: a line left in the
	// administrator's cart outlives the test and is dropped in a later one.
	await usePlainPermalinks(page, baseURL);
	const permalink = `/?post_type=product&p=${id}`;

	const mcp = await mcpSession(admin);
	const clock = String(Date.now()).slice(-SLUG_CLOCK_DIGITS);
	const made: number[] = [];
	const globalAttribute = async (
		name: string,
		slug: string,
		values: string[],
		from: string,
	) => {
		const attribute = await mcp.run<{ id: number }>(
			ABILITY('create-product-attribute'),
			{ fields: { name, slug } },
		);
		made.push(attribute.id);
		for (const value of values) {
			await mcp.run(ABILITY('create-attribute-term'), {
				attribute_id: attribute.id,
				fields: { name: value, slug: value.toLowerCase() },
			});
		}
		await mcp.run(ABILITY('move-product-attribute'), {
			from,
			to: `pa_${slug}`,
			product_ids: [id],
		});
		return attribute.id;
	};
	try {
		const size = await globalAttribute(
			'Storlek',
			`s${clock}`,
			Object.keys(SIZES),
			'size',
		);
		await globalAttribute(
			'Inramning',
			`f${clock}`,
			Object.keys(FRAMES),
			'finish',
		);
		const set = await mcp.run<{ choice_price: string }>(
			ABILITY('set-attribute-choice-price'),
			{ attribute_id: size, choice_price: 'price' },
		);
		expect(set.choice_price).toBe('price');
		const sizeField = `attribute_pa_s${clock}`;
		const frameField = `attribute_pa_f${clock}`;

		// The page opens on the first variation, bare: each size shows its
		// bare price, printed before any script on the classic form. A block
		// template writes the sizes' "x" as "×".
		await page.goto(permalink);
		await expect
			.poll(() => optionTexts(page, sizeField))
			.toEqual([
				expect.stringMatching(/^30[x×]40 · .*499/),
				expect.stringMatching(/^50[x×]70 · .*699/),
			]);

		await choose(page, frameField, 'ram');
		await expect
			.poll(() => optionTexts(page, sizeField))
			.toEqual([
				expect.stringMatching(/^30[x×]40 · .*699/),
				expect.stringMatching(/^50[x×]70 · .*899/),
			]);

		// The line's edit page shows the prices for the line's choices.
		await expect(page.locator('#pixonaut-form-front *').first()).toBeVisible();
		await addToCartButton(page).click();
		await expect(page).toHaveURL(/[?&]pixonaut_edit=[0-9a-f]+/);
		await expect
			.poll(() => optionTexts(page, sizeField))
			.toEqual([
				expect.stringMatching(/^30[x×]40 · .*699/),
				expect.stringMatching(/^50[x×]70 · .*899/),
			]);

		// Beside each swatch, the same price, without its name repeated.
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
			.getByRole('radiogroup', { name: /Storlek/ });
		await expect(
			swatches
				.getByRole('radio', { name: /50[x×]70/ })
				.locator('.pixonaut-swatches__price'),
		).toHaveText(/^[^·]*699/);
	} finally {
		for (const attribute of made) {
			await mcp.run(ABILITY('delete-product-attribute'), {
				id: attribute,
				force: true,
			});
		}
	}
});
