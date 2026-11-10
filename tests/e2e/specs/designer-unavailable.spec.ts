import { FIXTURE } from '../../fake-pixonaut/src/fixture-ids';
import { expect, restUrl, test } from '../fixtures';
import { addToCartButton, PERSONALISED } from '../personalised';

/** What the product page says when its designer cannot load. */
const UNAVAILABLE = /personalisation is unavailable/i;

/** The product's featured image and one gallery image. */
const IMAGES = 2;

/** The sizes the page is checked at: a desktop and a phone. */
const SHOT_SIZES = [
	{ width: 1280, height: 800 },
	{ width: 390, height: 844 },
];

test('a designer that cannot load shows the product’s images and keeps the buy row under its message', async ({
	connectedStore: _admin,
	customer,
	request,
}) => {
	const response = await request.post(restUrl('/pixonaut-e2e/v1/products'), {
		data: {
			name: `Unavailable tee ${Date.now()}`,
			pixonaut: PERSONALISED,
			variations: ['Small', 'Sold'],
			// The printshop no longer makes the Sold variation's variant.
			variants: {
				Small: FIXTURE.variant.teeWhiteS,
				Sold: FIXTURE.variant.teeRedS,
			},
			images: IMAGES,
		},
	});
	expect(response.status()).toBe(201);
	const { permalink } = await response.json();

	await customer.goto(permalink);
	const preview = customer.locator('.pixonaut-preview');
	const images = preview.locator('.pixonaut-preview__images img');
	await expect(images.first()).toBeHidden();

	await customer.locator('select[name="attribute_size"]').selectOption('Sold');
	const message = customer.getByText(UNAVAILABLE);
	await expect(message).toBeVisible();
	await expect(images).toHaveCount(IMAGES);
	for (const image of await images.all()) {
		await expect(image).toBeVisible();
	}
	await expect(preview.locator('.pixonaut-preview__frame')).toBeHidden();
	await expect(preview.locator('.pixonaut-preview__note')).toBeHidden();

	for (const size of SHOT_SIZES) {
		await customer.setViewportSize(size);
		// The quantity and the button stand under the message, inside the form.
		const said = await message.boundingBox();
		const button = await addToCartButton(customer).boundingBox();
		const form = await customer.locator('form.cart').boundingBox();
		expect(said && button && form).toBeTruthy();
		if (said && button && form) {
			expect(button.y).toBeGreaterThanOrEqual(said.y + said.height);
			expect(button.x + button.width).toBeLessThanOrEqual(form.x + form.width);
		}
		const name = `unavailable-${size.width}x${size.height}.png`;
		const path = test.info().outputPath(name);
		await customer.screenshot({ path, fullPage: true });
		await test.info().attach(name, { path, contentType: 'image/png' });
	}

	// A variation the designer can show brings the preview back.
	await customer.locator('select[name="attribute_size"]').selectOption('Small');
	await expect(message).toBeHidden();
	await expect(images.first()).toBeHidden();
	await expect(preview.locator('.pixonaut-preview__note')).toBeVisible();
});
