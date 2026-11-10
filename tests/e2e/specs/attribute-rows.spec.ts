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

	test(`an option in a step of its own sits right under the step's heading, with its Clear link under it, at ${viewport.width}px`, async ({
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
				variant: FIXTURE.variant.teeWhiteS,
				variants: { Dark: FIXTURE.variant.teeBlackS },
			},
		});
		expect(response.status()).toBe(201);
		await page.goto((await response.json()).permalink);
		await page.addStyleTag({ content: GRID_THEME });

		const row = page.locator(`${ROW}[data-pixonaut-own-step]`);
		const select = row.locator('select[name="attribute_size"]');
		await expect(select).toBeVisible();
		// Nothing of the row stands over the field, nor under it while
		// nothing is chosen.
		const all = await box(row);
		const field = await box(select);
		expect(Math.abs(field.y - all.y)).toBeLessThanOrEqual(SAME_PLACE_PX);
		expect(
			Math.abs(field.y + field.height - (all.y + all.height)),
		).toBeLessThanOrEqual(SAME_PLACE_PX);

		await select.selectOption('Dark');
		const clear = row.locator('.reset_variations');
		await expect(clear).toBeVisible();
		const chosen = await box(select);
		expect(Math.abs(chosen.y - field.y)).toBeLessThanOrEqual(SAME_PLACE_PX);
		const link = await box(clear);
		expect(link.y).toBeGreaterThanOrEqual(chosen.y + chosen.height);
		await clear.click();
		await expect(select).toHaveValue('');
	});
}
