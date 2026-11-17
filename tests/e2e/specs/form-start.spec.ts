import type { Locator, Page } from '@playwright/test';
import { FIXTURE } from '../../fake-pixonaut/src/fixture-ids';
import { expect, restUrl, test } from '../fixtures';
import { connectFixtureStore, PERSONALISED } from '../personalised';

// The designer takes the add-to-cart form's option fields into its own
// first step. What they leave behind in the form must take no room, so the
// designer starts where the form does, at the theme's own spacing under the
// title: a designer product's purchase panel prints no short description.

/** The first configuration version with an option's own group. */
const SCHEMA_VERSION = 12;

const VIEWPORTS = {
	desktop: { width: 1280, height: 800 },
	phone: { width: 390, height: 844 },
};

/** The space the theme below puts between the product summary's blocks and
 *  between the add-to-cart form's children. */
const BLOCK_GAP_PX = 16;

/** How far two edges may stand apart and still meet: themes and the SDK
 *  round their own sizes. */
const ROUNDING_PX = 1.5;

/** The add-to-cart form's own wrapper on a block theme, which stands among
 *  the summary's blocks in the classic form's place. */
const FORM_BLOCK =
	'.wp-block-woocommerce-add-to-cart-form, .wp-block-woocommerce-add-to-cart-with-options';

/** A theme that lays out its purchase panel as pixonaut-shop does: the
 *  summary's blocks one block gap apart, and the add-to-cart form a column
 *  of its children, a gap between each, without WooCommerce's clearfix
 *  boxes and with no space of their own inside it. */
const STACKED_PANEL_THEME = `
	.pixonaut-test-panel > * { margin-block: 0 !important; }
	.pixonaut-test-panel > * + * { margin-block-start: ${BLOCK_GAP_PX}px !important; }
	.pixonaut-test-panel .woocommerce-product-details__short-description > p,
	.pixonaut-test-panel .wp-block-post-excerpt__excerpt { margin: 0 !important; }
	form.cart {
		display: flex !important;
		flex-direction: column;
		gap: ${BLOCK_GAP_PX}px;
		padding: 0 !important;
	}
	form.cart::before, form.cart::after { display: none !important; }
	.single_variation_wrap { display: flex; flex-direction: column; }
	:is(form.cart, .single_variation_wrap) > * { margin: 0 !important; padding: 0 !important; }
`;

/** The tee's size and finish in the designer's first step, as a variable
 *  product's options are. */
const OPTIONS_FIRST = {
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

const DESIGNER_FORM = '#pixonaut-form-front [data-testid="pixonaut-form"]';

/** The product's short description, which its purchase panel leaves out. */
const SHORT_DESCRIPTION = 'A birth poster, printed to order.';

test.beforeEach(async ({ request, baseURL }) => {
	await connectFixtureStore(request, baseURL ?? '');
});

test.afterEach(async ({ request }) => {
	await request.delete(restUrl('/pixonaut-e2e/v1/connection'));
});

async function top(locator: Locator): Promise<number> {
	const found = await locator.boundingBox();
	expect(found, 'laid out').not.toBeNull();
	return found?.y ?? 0;
}

/** The bottom of the last line printed above the add-to-cart form: the
 *  title's and whatever the theme prints with it. */
async function bottomAbove(page: Page): Promise<number> {
	return page.locator('form.cart').evaluate((form, selector) => {
		const outer = form.closest(selector) ?? form;
		for (
			let above = outer.previousElementSibling;
			above !== null;
			above = above.previousElementSibling
		) {
			const box = above.getBoundingClientRect();
			if (box.height > 0) {
				return box.bottom;
			}
		}
		throw new Error('nothing is printed above the add-to-cart form');
	}, FORM_BLOCK);
}

/** Opens a variable product whose options the designer draws in its first
 *  step, under the stacked panel theme. */
async function openProduct(
	page: Page,
	request: Parameters<typeof connectFixtureStore>[0],
	name: string,
	shortDescription: string,
): Promise<void> {
	const response = await request.post(restUrl('/pixonaut-e2e/v1/products'), {
		data: {
			name: `${name} ${Date.now()}`,
			short_description: shortDescription,
			pixonaut: OPTIONS_FIRST,
			variations: ['Tee', 'Dark'],
			finishes: ['Matt', 'Gloss'],
			variant: FIXTURE.variant.teeWhiteS,
			variants: { Dark: FIXTURE.variant.teeBlackS },
		},
	});
	expect(response.status()).toBe(201);
	await page.goto((await response.json()).permalink);
	await page.locator('form.cart').evaluate((form, selector) => {
		(form.closest(selector) ?? form).parentElement?.classList.add(
			'pixonaut-test-panel',
		);
	}, FORM_BLOCK);
	await page.addStyleTag({ content: STACKED_PANEL_THEME });
	await page.locator('.pixonaut-preview').first().scrollIntoViewIfNeeded();
	await expect(
		page.locator(`${DESIGNER_FORM} [name="attribute_size"]`),
	).toBeAttached();
	await expect(page.locator(DESIGNER_FORM)).toBeVisible();
}

for (const [device, viewport] of Object.entries(VIEWPORTS)) {
	test(`the designer starts one block gap under the title, with no short description, on a ${device}`, async ({
		page,
		request,
		theme,
	}) => {
		await page.setViewportSize(viewport);
		await openProduct(page, request, `Form start ${theme}`, SHORT_DESCRIPTION);

		// What the designer emptied is not drawn.
		const emptied = page.locator(
			'form.cart table.variations:not(:has(tr)), form.cart .wp-block-woocommerce-add-to-cart-with-options-variation-selector:not(:has(.wp-block-woocommerce-add-to-cart-with-options-variation-selector-attribute))',
		);
		// Playwright counts an empty box as hidden already: ask for the
		// box itself, which a flex or grid form spaces like any other.
		const displays = await emptied.evaluateAll((parts) =>
			parts.map((part) => getComputedStyle(part).display),
		);
		expect(displays.length, 'parts the designer emptied').toBeGreaterThan(0);
		expect(
			displays.filter((display) => display !== 'none'),
			'emptied parts drawn',
		).toEqual([]);

		// The designer is the first thing in the form.
		const designer = await top(page.locator(DESIGNER_FORM));
		expect(
			Math.abs(designer - (await top(page.locator('form.cart')))),
			'space above the designer in the add-to-cart form',
		).toBeLessThanOrEqual(ROUNDING_PX);

		// The product's short description is not printed: the designer
		// stands one block gap under the title.
		await expect(page.getByText(SHORT_DESCRIPTION)).toHaveCount(0);
		expect(
			Math.abs(designer - (await bottomAbove(page)) - BLOCK_GAP_PX),
			'space between the summary and the designer, beyond one block gap',
		).toBeLessThanOrEqual(ROUNDING_PX);
	});
}
