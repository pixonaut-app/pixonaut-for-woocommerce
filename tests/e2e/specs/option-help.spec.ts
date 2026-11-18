import { FIXTURE } from '../../fake-pixonaut/src/fixture-ids';
import { expect, restUrl, test } from '../fixtures';
import { connectFixtureStore, PERSONALISED } from '../personalised';

// A line a product's arrangement gives one of its options is printed under
// the option's field, in the add-to-cart form's own markup, so it goes with
// the field into the designer's form: under a classic select, or under the
// block form's chips or dropdown.

/** The first configuration version with an option's own group. */
const SCHEMA_VERSION = 12;

/** The line under the size. */
const SIZE_HELP = 'Printed on organic cotton';

/** The tee's size in a step of its own, with a line under it, and its
 *  finish with none. */
const WITH_HELP = {
	...PERSONALISED,
	v: SCHEMA_VERSION,
	formLayout: 'steps',
	sides: PERSONALISED.sides.map((side) => ({
		...side,
		formGroups: {
			groups: [
				{
					id: 'wc-size',
					title: 'Choose a size',
					items: [{ attribute: 'size', help: SIZE_HELP }],
				},
				{
					id: 'wc-finish',
					title: 'Choose a finish',
					items: [{ attribute: 'finish' }],
				},
			],
		},
	})),
};

/** The block form's part of one attribute, and the blocks drawing its
 *  options. */
const BLOCK_ATTRIBUTE =
	'.wp-block-woocommerce-add-to-cart-with-options-variation-selector-attribute';
const BLOCK_FIELDS =
	'.wp-block-woocommerce-product-filter-chips, .wp-block-woocommerce-dropdown';

/** The designer's own form, which the steps are drawn in. */
const DESIGNER_FORM = '#pixonaut-form-front';

test.beforeEach(async ({ request, baseURL }) => {
	await connectFixtureStore(request, baseURL ?? '');
});

test.afterEach(async ({ request }) => {
	await request.delete(restUrl('/pixonaut-e2e/v1/connection'));
});

test('the line an option is given stands under its field in the designer', async ({
	page,
	request,
	theme,
}) => {
	const response = await request.post(restUrl('/pixonaut-e2e/v1/products'), {
		data: {
			name: `Option help ${theme} ${Date.now()}`,
			pixonaut: WITH_HELP,
			variations: ['Tee', 'Dark'],
			finishes: ['Matt', 'Gloss'],
			variant: FIXTURE.variant.teeWhiteS,
			variants: { Dark: FIXTURE.variant.teeBlackS },
		},
	});
	expect(response.status()).toBe(201);
	await page.goto((await response.json()).permalink);
	await page.locator(DESIGNER_FORM).scrollIntoViewIfNeeded();

	const form = page.locator(DESIGNER_FORM);
	const help = form.locator('.pixonaut-option-help');
	await expect(help).toHaveCount(1);
	await expect(help).toHaveText(SIZE_HELP);
	await expect(help).toBeVisible();

	// It ends the size's own part of the form, under its field.
	const field = form
		.locator(
			`select[name="attribute_size"], ${BLOCK_ATTRIBUTE}:has([name="attribute_size"]) :is(${BLOCK_FIELDS})`,
		)
		.first();
	const fieldBox = await field.boundingBox();
	const helpBox = await help.boundingBox();
	expect(fieldBox).not.toBeNull();
	expect(helpBox).not.toBeNull();
	expect(helpBox?.y ?? 0).toBeGreaterThanOrEqual(
		(fieldBox?.y ?? 0) + (fieldBox?.height ?? 0) - 1,
	);
	await expect(
		form
			.locator(`table.variations, ${BLOCK_ATTRIBUTE}`)
			.filter({ has: page.locator('[name="attribute_finish"]') })
			.locator('.pixonaut-option-help'),
	).toHaveCount(0);
});
