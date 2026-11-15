import type { APIRequestContext, Locator, Page } from '@playwright/test';
import { expect, restUrl, test } from '../fixtures';
import { openCheckouts, pay } from '../orders';
import { addToCartButton } from '../personalised';

/** Most digits of the clock a unique slug takes: attribute slugs are short. */
const SLUG_CLOCK_DIGITS = 8;

/** The poster's attributes, as the support route names them. */
type Attribute = 'inramning' | 'hangarfarg' | 'ramfarg' | 'canvasdjup';

const ATTRIBUTES: Attribute[] = [
	'inramning',
	'hangarfarg',
	'ramfarg',
	'canvasdjup',
];

type Poster = {
	id: number;
	permalink: string;
	category: string;
	taxonomies: Record<Attribute, { id: number; taxonomy: string }>;
	variations: Record<string, number>;
};

/** A row of the classic form's table, or an attribute of the block form. */
const ROW =
	'tr, .wp-block-woocommerce-add-to-cart-with-options-variation-selector-attribute';

/** A poster sold bare, with a hanger, framed or on canvas, each extra with
 *  an option of its own, listed by `split`; removed again after `run`. */
async function withPoster(
	request: APIRequestContext,
	split: Attribute[],
	run: (poster: Poster) => Promise<void>,
): Promise<void> {
	const key = String(Date.now()).slice(-SLUG_CLOCK_DIGITS);
	const response = await request.post(
		restUrl('/pixonaut-e2e/v1/dependent-poster'),
		{ data: { key, split } },
	);
	expect(response.status()).toBe(201);
	try {
		await run((await response.json()) as Poster);
	} finally {
		await request.delete(restUrl('/pixonaut-e2e/v1/dependent-poster'), {
			params: { key },
		});
	}
}

function field(poster: Poster, attribute: Attribute): string {
	return `attribute_${poster.taxonomies[attribute].taxonomy}`;
}

/** The form's row of one attribute: its name and its field. */
function row(page: Page, poster: Poster, attribute: Attribute): Locator {
	return page
		.locator('form')
		.locator(ROW)
		.filter({ has: page.locator(`[name="${field(poster, attribute)}"]`) });
}

/** Chooses a value as a customer does: in the classic form's menu, or on
 *  the block form's chip. */
async function choose(
	page: Page,
	poster: Poster,
	attribute: Attribute,
	value: string,
): Promise<void> {
	const menu = row(page, poster, attribute).locator('select');
	if ((await menu.count()) > 0) {
		await menu.selectOption(value);
		return;
	}
	await row(page, poster, attribute)
		.locator(`button[role="radio"][value="${value}"]`)
		.click();
}

/** Only these attributes' rows show. */
async function expectShown(
	page: Page,
	poster: Poster,
	shown: Attribute[],
): Promise<void> {
	for (const attribute of ATTRIBUTES) {
		await expect(
			row(page, poster, attribute),
			`${attribute} ${shown.includes(attribute) ? 'shown' : 'hidden'}`,
		).toBeVisible({ visible: shown.includes(attribute) });
	}
}

/** The names of the three extras' options, as a line would print them. */
const EXTRA_OPTIONS = /Hängarfärg|Ramfärg|Canvasdjup/;

test('a product page shows only the options that apply to the choice', async ({
	page,
	request,
}) => {
	await withPoster(request, [], async (poster) => {
		await page.goto(poster.permalink);
		await expectShown(page, poster, ATTRIBUTES);

		await choose(page, poster, 'inramning', 'ingen');
		await expectShown(page, poster, ['inramning']);
		// Every hidden field holds a value, so WooCommerce finds the variation.
		await expect(page.locator('form [name="variation_id"]')).toHaveValue(
			String(poster.variations.ingen),
		);

		await choose(page, poster, 'inramning', 'hangare');
		await expectShown(page, poster, ['inramning', 'hangarfarg']);
		// Shown again, the colour is the customer's to choose.
		await expect(
			page.locator(`form [name="${field(poster, 'hangarfarg')}"]`),
		).toHaveValue('');
		await choose(page, poster, 'hangarfarg', 'svart');
		await expect(page.locator('form [name="variation_id"]')).toHaveValue(
			String(poster.variations['hangare-svart']),
		);

		await choose(page, poster, 'inramning', 'ram');
		await expectShown(page, poster, ['inramning', 'ramfarg']);
		await choose(page, poster, 'inramning', 'canvas');
		await expectShown(page, poster, ['inramning', 'canvasdjup']);
	});
});

test('an order of a bare poster carries no option of an extra', async ({
	customer,
	request,
	checkout,
}) => {
	const checkoutUrls = await openCheckouts(request);
	await withPoster(request, [], async (poster) => {
		await customer.goto(poster.permalink);
		await choose(customer, poster, 'inramning', 'ingen');
		await expectShown(customer, poster, ['inramning']);
		// The classic form enables its button a moment after it found the variation.
		await expect(addToCartButton(customer)).not.toHaveClass(/disabled/);
		await addToCartButton(customer).click();
		// The classic form's notice, or the block form's announcement.
		await expect(
			customer.getByText(/has been added to your cart|Added to cart/).first(),
		).toBeAttached();

		const carts = await request.post(restUrl('/pixonaut-e2e/v1/cart'));
		const cartUrls: Record<'classic' | 'block', string> = await carts.json();
		await customer.goto(cartUrls[checkout]);
		const cart = customer.locator('main');
		await expect(cart).toContainText('Ingen');
		await expect(cart).not.toContainText(EXTRA_OPTIONS);

		await pay(customer, checkout, checkoutUrls);
		const received = customer.locator('main');
		await expect(received).toContainText('Ingen');
		await expect(received).not.toContainText(EXTRA_OPTIONS);
	});
});
