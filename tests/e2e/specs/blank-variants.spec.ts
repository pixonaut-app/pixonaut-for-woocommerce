import type { APIRequestContext } from '@playwright/test';
import { FIXTURE } from '../../fake-pixonaut/src/fixture-ids';
import { expect, restUrl, test } from '../fixtures';
import { addToCartButton, PERSONALISED } from '../personalised';

/** Each size sells another of the tee's variants: one on sale, one on sale
 *  that no photo shows, one the printshop no longer makes, one the merchant
 *  retired, and one that is no variant of the tee. */
const VARIANTS = {
	Small: FIXTURE.variant.teeWhiteS,
	Heather: FIXTURE.variant.teeHeatherS,
	Sold: FIXTURE.variant.teeRedS,
	Retired: FIXTURE.variant.teeRedM,
	Unknown: 'v_not_of_the_tee',
};

/** What the product page says of a variation that cannot be personalised. */
const UNAVAILABLE = /personalisation is unavailable/i;

async function createProduct(
	request: APIRequestContext,
	name: string,
): Promise<{ id: number; permalink: string }> {
	const response = await request.post(restUrl('/pixonaut-e2e/v1/products'), {
		data: {
			name,
			pixonaut: PERSONALISED,
			variations: Object.keys(VARIANTS),
			variants: VARIANTS,
		},
	});
	expect(response.status()).toBe(201);
	return response.json();
}

test('a variation without a blank variant, or with a retired one, cannot be bought and is named in a notice', async ({
	connectedStore: admin,
	customer,
	request,
}) => {
	const name = `Variant tee ${Date.now()}`;
	const { id, permalink } = await createProduct(request, name);

	await customer.goto(permalink);
	const size = customer.locator('select[name="attribute_size"]');
	await size.selectOption('Small');
	await expect(addToCartButton(customer)).not.toHaveClass(/\bdisabled\b/);
	await expect(
		customer.locator('#pixonaut-form-front *').first(),
	).toBeVisible();
	await expect(customer.getByText(UNAVAILABLE)).toBeHidden();
	// The designer cannot show a variant no view has a photo of.
	for (const unavailable of ['Heather', 'Sold', 'Retired', 'Unknown']) {
		await size.selectOption(unavailable);
		await expect(customer.getByText(UNAVAILABLE), unavailable).toBeVisible();
	}

	await admin.goto(`/wp-admin/post.php?post=${id}&action=edit`);
	const notice = admin.locator('.notice', { hasText: name });
	await expect(notice).toContainText(
		'variation Sold, whose blank variant is retired',
	);
	await expect(notice).toContainText(
		'variation Retired, whose blank variant is retired',
	);
	await expect(notice).toContainText(
		'variation Unknown, which is no variant of its blank',
	);
	await expect(notice).not.toContainText('Small');
	await expect(notice).not.toContainText('Heather');

	await admin.locator('.variations_tab a').click();
	const small = admin.locator('.woocommerce_variation', {
		has: admin.locator(
			'select[name^="attribute_size"] option[value="Small"]:checked',
		),
	});
	await small.locator('h3 strong').first().click();
	const variant = small.getByLabel('Blank variant');
	await expect(variant.locator('option:checked')).toHaveText(
		'Color: White, Size: S',
	);
	await expect(
		variant.locator(`option[value="${FIXTURE.variant.teeRedM}"]`),
	).toHaveText('Color: Red, Size: M (retired)');

	const heather = admin.locator('.woocommerce_variation', {
		has: admin.locator(
			'select[name^="attribute_size"] option[value="Heather"]:checked',
		),
	});
	await heather.locator('h3 strong').first().click();
	await expect(heather).toContainText(
		'No view the product is shown in has a photo of this variant on Front',
	);
});
