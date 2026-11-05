import type { Page } from '@playwright/test';
import { FIXTURE } from '../../fake-pixonaut/src/fixture-ids';
import { expect, restUrl, test } from '../fixtures';
import { connectFixtureStore, PERSONALISED } from '../personalised';

/** Read by the e2e support mu-plugin: the store's `attribute_options`
 *  setting for this browser context. */
const ATTRIBUTE_OPTIONS_COOKIE = 'pixonaut_e2e_attribute_options';

/** The designer's own form, which draws the variation fields in its first
 *  step. */
function designerForm(page: Page) {
	return page.locator('#pixonaut-form-front');
}

/** The form's chosen variation, '' for none. */
async function variationId(page: Page): Promise<string> {
	const id = await page.locator('input[name="variation_id"]').inputValue();
	return id === '0' ? '' : id;
}

test.beforeEach(async ({ request, baseURL }) => {
	await connectFixtureStore(request, baseURL ?? '');
});

test.afterEach(async ({ request }) => {
	await request.delete(restUrl('/pixonaut-e2e/v1/connection'));
});

test('a swatch chooses the variation as the theme’s own field does, by mouse and by keyboard', async ({
	page,
	request,
	theme,
	blocks,
	baseURL,
}) => {
	await page
		.context()
		.addCookies([
			{ name: ATTRIBUTE_OPTIONS_COOKIE, value: 'swatches', url: baseURL ?? '' },
		]);
	const response = await request.post(restUrl('/pixonaut-e2e/v1/products'), {
		data: {
			name: `Swatches ${theme} ${blocks} ${Date.now()}`,
			pixonaut: PERSONALISED,
			variations: ['Tee', 'Dark'],
			variant: FIXTURE.variant.teeWhiteS,
			variants: { Dark: FIXTURE.variant.teeBlackS },
		},
	});
	expect(response.status()).toBe(201);
	const classic = blocks === 'primary';
	// The classic form's own events, counted from the start.
	if (classic) {
		await page.addInitScript(() => {
			const counts = { changed: 0, found: 0 };
			(
				window as unknown as { variationEvents: typeof counts }
			).variationEvents = counts;
			document.addEventListener('DOMContentLoaded', () => {
				const jQuery = (
					window as unknown as {
						jQuery: (selector: string) => {
							on: (event: string, handler: () => void) => void;
						};
					}
				).jQuery;
				jQuery('form.variations_form').on(
					'woocommerce_variation_select_change',
					() => {
						counts.changed++;
					},
				);
				jQuery('form.variations_form').on('found_variation', () => {
					counts.found++;
				});
			});
		});
	}
	await page.goto((await response.json()).permalink);

	const form = designerForm(page);
	const swatches = form.getByRole('radiogroup', { name: /size/i });
	await expect(swatches).toBeVisible();
	// The theme's field stays in the form, out of sight.
	const themeField = classic
		? page.locator('select[name="attribute_size"]')
		: page.locator(
				'.wp-block-woocommerce-add-to-cart-with-options-variation-selector-attribute [role="radiogroup"]:not(.pixonaut-swatches)',
			);
	await expect(themeField).toHaveCount(1);
	await expect(themeField).toBeHidden();

	const dark = swatches.getByRole('radio', { name: 'Dark' });
	await dark.click();
	await expect(dark).toHaveAttribute('aria-checked', 'true');
	await expect.poll(() => variationId(page)).not.toBe('');
	// The designer follows the variation, as it does a choice in the field.
	await expect(
		page.getByRole('button', { name: 'Front, dark' }),
	).toHaveAttribute('aria-pressed', 'true');
	const darkVariation = await variationId(page);
	if (classic) {
		const events = await page.evaluate(
			() =>
				(
					window as unknown as {
						variationEvents: { changed: number; found: number };
					}
				).variationEvents,
		);
		expect(events.changed).toBeGreaterThan(0);
		expect(events.found).toBeGreaterThan(0);
	}

	// The arrows move the choice, as in any radio group.
	await dark.focus();
	await page.keyboard.press('ArrowLeft');
	const tee = swatches.getByRole('radio', { name: 'Tee' });
	await expect(tee).toHaveAttribute('aria-checked', 'true');
	await expect(tee).toBeFocused();
	await expect.poll(() => variationId(page)).not.toBe(darkVariation);
	await expect.poll(() => variationId(page)).not.toBe('');
});
