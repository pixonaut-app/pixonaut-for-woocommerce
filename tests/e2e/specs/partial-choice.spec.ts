import type { APIRequestContext, Page } from '@playwright/test';
import { FIXTURE } from '../../fake-pixonaut/src/fixture-ids';
import { countMounts, mounts } from '../designer-watch';
import { expect, restUrl, test } from '../fixtures';
import { connectFixtureStore, PERSONALISED } from '../personalised';

/** The tee on the hoodie: another blank, so the side mounts again. */
const HOODIE = {
	...PERSONALISED,
	blank: { id: FIXTURE.blank.hoodie, name: 'Fixture hoodie' },
};

/** What the form's variation field holds while its choices name none. */
const NO_VARIATION = ['', '0'];

test.beforeEach(async ({ request, baseURL }) => {
	await connectFixtureStore(request, baseURL ?? '');
});

test.afterEach(async ({ request }) => {
	await request.delete(restUrl('/pixonaut-e2e/v1/connection'));
});

/** A tee or a hoodie, each in either finish: two attributes, so one choice
 *  names no variation. */
async function createProduct(
	request: APIRequestContext,
	name: string,
): Promise<string> {
	const response = await request.post(restUrl('/pixonaut-e2e/v1/products'), {
		data: {
			name,
			pixonaut: PERSONALISED,
			variations: ['Tee', 'Hoodie'],
			finishes: ['Matt', 'Gloss'],
			overrides: { Hoodie: HOODIE },
			variant: FIXTURE.variant.teeWhiteS,
			variants: { Hoodie: FIXTURE.variant.hoodieWhiteS },
		},
	});
	expect(response.status()).toBe(201);
	return (await response.json()).permalink;
}

function field(page: Page, name: string) {
	return page.locator(`select[name="attribute_${name}"]`);
}

/** The id the form would add to the cart. */
function heldVariation(page: Page): Promise<string> {
	return page
		.locator('form.cart, form.wc-block-add-to-cart-with-options')
		.first()
		.evaluate((form) =>
			String(new FormData(form as HTMLFormElement).get('variation_id') ?? ''),
		);
}

test('one option picked is drawn at once, before the other is chosen', async ({
	page,
	request,
	theme,
}) => {
	await countMounts(page);
	await page.goto(
		await createProduct(
			request,
			`Tee or hoodie, by finish ${theme} ${Date.now()}`,
		),
	);
	await field(page, 'finish').selectOption('');
	await field(page, 'size').selectOption('Tee');
	await expect(page.locator('.pixonaut-preview__frame')).toHaveClass(
		/is-drawn/,
	);
	expect(await mounts(page)).toBe(1);

	await field(page, 'size').selectOption('Hoodie');

	await expect.poll(() => mounts(page), 'the hoodie is drawn').toBe(2);
	await expect(field(page, 'finish')).toHaveValue('');
	expect(NO_VARIATION, 'the form still holds no variation').toContain(
		await heldVariation(page),
	);

	await field(page, 'finish').selectOption('Matt');

	await expect
		.poll(() => heldVariation(page), 'the whole choice names a variation')
		.not.toMatch(/^0?$/);
	expect(await mounts(page), 'what was drawn stays').toBe(2);
});
