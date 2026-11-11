import { FIXTURE } from '../../fake-pixonaut/src/fixture-ids';
import {
	expect,
	type FakePixonaut,
	test,
	usePlainPermalinks,
} from '../fixtures';
import { connectFixtureStore } from '../personalised';
import { openPixonautTab, pick, productPanel, publish } from '../products';

/** A picture 4800 px wide: 174 dpi across 700 mm, 435 across 280. */
const PHOTO = { layerId: 'photo', assetId: 'ast_photo', widthPx: 4800 };

/** The T-shirt template's own 280:360 shape, sharp enough for the photo. */
const SMALL = { width: '280', height: '360', label: 'Small' };

/** The same shape 700 mm wide, where the photo prints too soft. */
const LARGE = { width: '700', height: '900', label: 'Large' };

/** Tells the fake the T-shirt template holds the photo, and what the
 *  workspace does with a size it prints too soft at. */
async function holdPhoto(
	fake: FakePixonaut,
	action: 'warn' | 'refuse',
): Promise<void> {
	const told = await fake.control.post(
		`/__fake/templates/${FIXTURE.template.live}/low-resolution`,
		{ data: { images: [PHOTO], recommendedDpi: 300, action } },
	);
	expect(told.ok(), 'the fake takes the images').toBe(true);
}

test('a print size that prints an image too soft is warned of until accepted, and said why when refused', async ({
	admin: page,
	fake,
	request,
	baseURL,
	theme,
}) => {
	test.skip(theme !== 'storefront', 'wp-admin does not depend on the theme');
	await holdPhoto(fake, 'warn');
	// Connecting clears the template reads the store keeps.
	await connectFixtureStore(request, baseURL ?? '');
	await usePlainPermalinks(page, baseURL);
	await page.goto('/wp-admin/post-new.php?post_type=product');
	await page.locator('#title').fill(`Soft photo sign ${Date.now()}`);
	await page.locator('#_regular_price').fill('20');
	await openPixonautTab(page);
	const panel = productPanel(page);
	await panel.getByLabel('Personalise').check();
	await pick(page, 'pixonaut_side_0_template', 'shirt', 'T-shirt med tryck');
	const side = panel.locator('.pixonaut-side[data-index="0"]');
	for (const [row, size] of [SMALL, LARGE].entries()) {
		if (row > 0) {
			await side.getByRole('button', { name: 'Add a size in mm' }).click();
		}
		const fields = side.locator('.pixonaut-size').nth(row);
		await fields.getByLabel('Label customers see').fill(size.label);
		await fields.getByLabel('Width in millimetres').fill(size.width);
		await fields.getByLabel('Height in millimetres').fill(size.height);
	}
	await publish(page);

	// Sold, and warned of at the large size alone.
	await expect(page.locator('.pixonaut-notice')).toHaveCount(0);
	await openPixonautTab(page);
	const warnings = side.locator('.pixonaut-low-resolution__row');
	await expect(warnings).toHaveCount(1);
	await expect(warnings).toContainText(
		'The image in “photo” prints at 174 dpi at Large (700 × 900 mm), below the 300 dpi recommended',
	);
	await expect(
		warnings.getByRole('link', { name: 'Upscale in Pixonaut' }),
	).toHaveAttribute('href', /\/assets\?asset=ast_photo$/);

	// Accepted at that size, it is no longer shown.
	await warnings
		.getByLabel('What to do about this image')
		.selectOption({ label: 'Accept at this size' });
	await publish(page);
	await openPixonautTab(page);
	await expect(side.locator('.pixonaut-low-resolution')).toHaveCount(0);

	// A workspace that refuses such sizes stops selling it, and says why.
	await holdPhoto(fake, 'refuse');
	await publish(page);
	await expect(page.locator('.pixonaut-notice').first()).toContainText(
		'An image in the template prints below its recommended resolution at this size',
	);
	await openPixonautTab(page);
	await expect(warnings).toContainText('so Pixonaut does not sell this size');
	await expect(
		warnings.getByRole('link', { name: 'Accept in Pixonaut' }),
	).toBeVisible();
	await expect(warnings.getByLabel('What to do about this image')).toHaveCount(
		0,
	);
});
