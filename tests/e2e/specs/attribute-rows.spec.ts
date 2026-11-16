import type { Locator } from '@playwright/test';
import { FIXTURE } from '../../fake-pixonaut/src/fixture-ids';
import { expect, restUrl, test } from '../fixtures';
import { connectFixtureStore, PERSONALISED } from '../personalised';

/** Read by the e2e support mu-plugin: the store's `attribute_options` and
 *  product-form settings for this browser context. */
const ATTRIBUTE_OPTIONS_COOKIE = 'pixonaut_e2e_attribute_options';
const PRODUCT_FORM_COOKIE = 'pixonaut_e2e_product_form';

/** The first configuration version with an option's own group. */
const SCHEMA_VERSION = 12;

const DESKTOP = { width: 1280, height: 800 };
const PHONE = { width: 390, height: 844 };

/** Most a part may stand from where the row puts it. */
const SAME_PLACE_PX = 1.5;

const FRONT = '#pixonaut-form-front';
const DESIGNER_FORM = `${FRONT} [data-testid="pixonaut-form"]`;

/** The designer's cart row: the price, the quantity and Add to cart. */
const CART_ROW = `${DESIGNER_FORM} .pixonaut-cart-row`;

/** One attribute's row in the designer's form. */
const ROW = `${DESIGNER_FORM} table.pixonaut-attribute`;

/** A theme that lays out its variations table as a grid, as pixonaut-shop
 *  did: the Clear link in the label's row, at its end, and the menu under
 *  it, with no rule for the plugin's swatches, which take any free cell. */
const GRID_THEME = `
	table.variations, table.variations tbody { display: block; }
	table.variations tr {
		display: grid;
		grid-template-columns: minmax(0, 1fr) auto;
		gap: 8px 12px;
		align-items: baseline;
	}
	table.variations th { display: block; grid-column: 1; }
	table.variations td { display: contents; }
	table.variations select { grid-column: 1 / -1; }
	table.variations .reset_variations { grid-row: 1; grid-column: 2; }
`;

/** The tee's options in one step of the designer's form: its size, then its
 *  finish, whose row holds the Clear link, as the form prints it in its
 *  last row. */
const TWO_OPTIONS = {
	...PERSONALISED,
	v: SCHEMA_VERSION,
	formLayout: 'steps',
	sides: PERSONALISED.sides.map((side) => ({
		...side,
		formGroups: {
			groups: [
				{
					id: 'options-front',
					title: 'Size and finish',
					items: [{ attribute: 'size' }, { attribute: 'finish' }],
				},
			],
		},
	})),
};

test.beforeEach(async ({ request, baseURL }) => {
	await connectFixtureStore(request, baseURL ?? '');
});

test.afterEach(async ({ request }) => {
	await request.delete(restUrl('/pixonaut-e2e/v1/connection'));
});

async function box(locator: Locator) {
	const found = await locator.boundingBox();
	expect(found, 'laid out').not.toBeNull();
	return found ?? { x: 0, y: 0, width: 0, height: 0 };
}

/** The row's label stands over its field, which takes the whole row. */
async function expectStacked(row: Locator, field: Locator): Promise<void> {
	const all = await box(row);
	const label = await box(row.locator('th'));
	const control = await box(field);
	expect(control.y).toBeGreaterThanOrEqual(label.y + label.height);
	expect(Math.abs(control.x - all.x)).toBeLessThanOrEqual(SAME_PLACE_PX);
	expect(
		Math.abs(control.x + control.width - (all.x + all.width)),
	).toBeLessThanOrEqual(SAME_PLACE_PX);
}

for (const viewport of [DESKTOP, PHONE]) {
	for (const grid of [false, true]) {
		test(`two options in a step each stack their swatches under their label, with or without the Clear link${grid ? ', in a grid theme' : ''}, at ${viewport.width}px`, async ({
			page,
			request,
			baseURL,
			theme,
		}) => {
			await page.setViewportSize(viewport);
			await page.context().addCookies([
				{
					name: ATTRIBUTE_OPTIONS_COOKIE,
					value: 'swatches',
					url: baseURL ?? '',
				},
			]);
			const response = await request.post(
				restUrl('/pixonaut-e2e/v1/products'),
				{
					data: {
						name: `Rows ${theme} ${Date.now()}`,
						pixonaut: TWO_OPTIONS,
						variations: ['Tee', 'Dark'],
						finishes: ['Matt', 'Gloss'],
						variant: FIXTURE.variant.teeWhiteS,
						variants: { Dark: FIXTURE.variant.teeBlackS },
					},
				},
			);
			expect(response.status()).toBe(201);
			await page.goto((await response.json()).permalink);
			if (grid) {
				await page.addStyleTag({ content: GRID_THEME });
			}

			const rows = page.locator(ROW);
			await expect(rows).toHaveCount(2);
			const size = rows.filter({
				has: page.locator('[name="attribute_size"]'),
			});
			const finish = rows.filter({
				has: page.locator('[name="attribute_finish"]'),
			});
			await expect(finish.locator('.reset_variations')).toHaveCount(1);
			await expect(size.locator('.reset_variations')).toHaveCount(0);
			for (const row of [size, finish]) {
				await expect(row.locator('.pixonaut-swatches')).toBeVisible();
			}

			// The Clear link shows once a choice is made, at the end of the
			// label's row.
			await size.getByRole('radio', { name: 'Dark' }).click();
			await finish.getByRole('radio', { name: 'Gloss' }).click();
			const clear = finish.locator('.reset_variations');
			await expect(clear).toBeVisible();
			for (const row of [size, finish]) {
				await expectStacked(row, row.locator('.pixonaut-swatches'));
			}
			const label = await box(finish.locator('th'));
			const link = await box(clear);
			expect(link.y).toBeLessThan(label.y + label.height);
			expect(link.x).toBeGreaterThan(label.x);
		});
	}

	test(`options in steps of their own sit right under their headings, with the Clear link for all of them over the cart row, at ${viewport.width}px`, async ({
		page,
		request,
		baseURL,
		theme,
	}) => {
		await page.setViewportSize(viewport);
		await page.context().addCookies([
			{
				name: PRODUCT_FORM_COOKIE,
				value: 'as_groups=yes',
				url: baseURL ?? '',
			},
		]);
		const response = await request.post(restUrl('/pixonaut-e2e/v1/products'), {
			data: {
				name: `Own row ${theme} ${Date.now()}`,
				pixonaut: { ...PERSONALISED, v: SCHEMA_VERSION },
				variations: ['Tee', 'Dark'],
				finishes: ['Matt', 'Gloss'],
				variant: FIXTURE.variant.teeWhiteS,
				variants: { Dark: FIXTURE.variant.teeBlackS },
			},
		});
		expect(response.status()).toBe(201);
		await page.goto((await response.json()).permalink);
		await page.addStyleTag({ content: GRID_THEME });

		const rows = page.locator(`${ROW}[data-pixonaut-own-step]`);
		await expect(rows).toHaveCount(2);
		const size = page.locator(`${DESIGNER_FORM} select[name="attribute_size"]`);
		const finish = page.locator(
			`${DESIGNER_FORM} select[name="attribute_finish"]`,
		);
		await expect(size).toBeVisible();
		// The page opens on the product's first variation.
		await size.selectOption('');
		await finish.selectOption('');
		// No step holds the link, and while nothing is chosen it takes no
		// room over the cart row.
		await expect(rows.locator('.reset_variations')).toHaveCount(0);
		const clear = page.locator(`${CART_ROW} > .reset_variations:first-child`);
		await expect(clear).toHaveCount(1);
		await expect(clear).toBeHidden();
		expect(await clear.boundingBox()).toBeNull();
		const cartRow = await box(page.locator(CART_ROW));
		const first = await box(
			page.locator(`${CART_ROW} > :not(.reset_variations):visible`).first(),
		);
		expect(Math.abs(first.y - cartRow.y)).toBeLessThanOrEqual(SAME_PLACE_PX);
		// Nothing of a row stands over its field, nor under it.
		for (const row of await rows.all()) {
			const all = await box(row);
			const field = await box(row.locator('select'));
			expect(Math.abs(field.y - all.y)).toBeLessThanOrEqual(SAME_PLACE_PX);
			// The menu takes the whole row, whatever width the theme gives it.
			expect(Math.abs(field.x - all.x)).toBeLessThanOrEqual(SAME_PLACE_PX);
			expect(
				Math.abs(field.x + field.width - (all.x + all.width)),
			).toBeLessThanOrEqual(SAME_PLACE_PX);
			expect(
				Math.abs(field.y + field.height - (all.y + all.height)),
			).toBeLessThanOrEqual(SAME_PLACE_PX);
		}

		await size.selectOption('Dark');
		await finish.selectOption('Gloss');
		await expect(clear).toBeVisible();
		await expect(clear).toHaveText('Clear options');
		// Under the last step, over the rest of the cart row.
		const link = await box(clear);
		const last = await box(rows.last());
		expect(link.y).toBeGreaterThanOrEqual(last.y + last.height);
		await clear.click();
		await expect(size).toHaveValue('');
		await expect(finish).toHaveValue('');
		await expect(clear).toBeHidden();
	});
}
