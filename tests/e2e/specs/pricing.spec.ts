import type { APIRequestContext } from '@playwright/test';
import { FIXTURE } from '../../fake-pixonaut/src/fixture-ids';
import { expect, restUrl, test, usePlainPermalinks } from '../fixtures';
import { PERSONALISED } from '../personalised';
import { openPixonautTab, openPrices, productPanel } from '../products';

/** Each size sells another of the tee's variants, which the fake's
 *  printshop charges 120.00 and 135.50 for, plus 4.90 shipping. */
const VARIANTS = {
	Small: FIXTURE.variant.teeWhiteS,
	Large: FIXTURE.variant.teeWhiteM,
};

/** What the store manager entered before any rule. */
const PRICES = { Small: '150.00', Large: '150.00' };

async function createProduct(
	request: APIRequestContext,
	name: string,
): Promise<number> {
	const response = await request.post(restUrl('/pixonaut-e2e/v1/products'), {
		data: {
			name,
			pixonaut: PERSONALISED,
			variations: Object.keys(VARIANTS),
			variants: VARIANTS,
			prices: PRICES,
		},
	});
	expect(response.status()).toBe(201);
	return (await response.json()).id;
}

test('prices are set from the printshop’s costs, and a cost that leaves the tolerance is flagged on the products list with the price to apply', async ({
	connectedStore: admin,
	request,
	fake,
	baseURL,
}) => {
	await usePlainPermalinks(admin, baseURL);
	const name = `Priced tee ${Date.now()}`;
	const id = await createProduct(request, name);

	await admin.goto(`/wp-admin/post.php?post=${id}&action=edit`);
	await openPixonautTab(admin);
	await openPrices(admin);
	const prices = productPanel(admin).locator('.pixonaut-prices');
	const row = (size: string) =>
		prices.locator('.pixonaut-prices__row', {
			has: admin.locator('.pixonaut-prices__name', { hasText: size }),
		});

	// Opening the section reads the costs: the product and its shipping.
	await expect(row('Small')).toContainText('$124.90');
	await expect(row('Small')).toContainText('$120.00 + $4.90 shipping');
	await expect(row('Large')).toContainText('$140.40');
	await expect(row('Small').locator('.pixonaut-prices__price')).toHaveText(
		'$150.00',
	);

	// A target margin of 50 % on steps of ten ending in nine, the store's steps.
	await prices.locator('[data-part="margin"]').fill('50');
	await prices.locator('.pixonaut-prices__apply').click();

	await expect(prices.locator('.pixonaut-prices__status')).toHaveText(
		'2 prices set.',
	);
	await expect(row('Small').locator('.pixonaut-prices__price')).toHaveText(
		'$249.00',
	);
	await expect(row('Large').locator('.pixonaut-prices__price')).toHaveText(
		'$279.00',
	);
	await expect(row('Small')).toContainText('49.8 %');
	await expect(row('Small')).toContainText('Follows the rule');

	// The printshop raises Small's price: its margin drops to 17.7 %.
	const raised = await fake.control.post(
		`/__fake/blanks/${FIXTURE.blank.tee}/costs`,
		{ data: { costs: { [VARIANTS.Small]: { product: '200.00' } } } },
	);
	expect(raised.ok()).toBe(true);
	await prices.locator('.pixonaut-prices__refresh').click();

	await expect(row('Small')).toContainText('The margin left the tolerance.');
	await expect(row('Small')).toContainText('Suggested: $409.00');
	await expect(row('Small').locator('.pixonaut-prices__price')).toHaveText(
		'$249.00',
	);
	await expect(row('Large')).not.toContainText('Suggested');

	await admin.goto('/wp-admin/edit.php?post_type=product');
	const listed = admin.locator(`#post-${id}`);
	await expect(listed.locator('.pixonaut-price-flag')).toContainText(
		'Margin off target',
	);
	await expect(listed.locator('.pixonaut-price-flag')).toContainText(
		'Small: $249.00 → $409.00 suggested',
	);

	await listed.locator('.pixonaut-price-flag__apply').click();

	await expect(admin.locator('.notice-success')).toContainText(
		'1 price was set to the price the pricing rule suggests.',
	);
	await expect(admin.locator(`#post-${id} .pixonaut-price-flag`)).toHaveCount(
		0,
	);
	await expect(admin.locator(`#post-${id} .column-price`)).toContainText(
		'409.00',
	);
});

test('a product set to always changes its price by itself when its cost leaves the tolerance, with the store’s automatic price changes off', async ({
	connectedStore: admin,
	request,
	fake,
	baseURL,
}) => {
	await usePlainPermalinks(admin, baseURL);
	const id = await createProduct(request, `Self-priced tee ${Date.now()}`);

	await admin.goto(`/wp-admin/post.php?post=${id}&action=edit`);
	await openPixonautTab(admin);
	await openPrices(admin);
	const prices = productPanel(admin).locator('.pixonaut-prices');
	const choice = prices.locator('.pixonaut-prices__changes');
	const small = prices.locator('.pixonaut-prices__row', {
		has: admin.locator('.pixonaut-prices__name', { hasText: 'Small' }),
	});
	await expect(small).toContainText('$124.90');

	// The store only suggests prices; this product is to change them itself.
	await expect(choice.locator('option:checked')).toHaveText(
		'As the store: off',
	);
	await choice.selectOption('always');
	await prices.locator('[data-part="margin"]').fill('50');
	await prices.locator('.pixonaut-prices__apply').click();
	await expect(prices.locator('.pixonaut-prices__status')).toHaveText(
		'2 prices set.',
	);
	await expect(choice).toHaveValue('always');

	// The printshop raises Small's price: its margin leaves the tolerance.
	const raised = await fake.control.post(
		`/__fake/blanks/${FIXTURE.blank.tee}/costs`,
		{ data: { costs: { [VARIANTS.Small]: { product: '200.00' } } } },
	);
	expect(raised.ok()).toBe(true);
	await prices.locator('.pixonaut-prices__refresh').click();

	await expect(prices.locator('.pixonaut-prices__status')).toHaveText(
		'1 price set.',
	);
	await expect(small.locator('.pixonaut-prices__price')).toHaveText('$409.00');
	await expect(small).not.toContainText('Suggested');

	// Apply stored the choice: the page read again still says so.
	await admin.reload();
	await openPixonautTab(admin);
	await openPrices(admin);
	await expect(choice).toHaveValue('always');
});
