import type { Page } from '@playwright/test';
import { FIXTURE } from '../../fake-pixonaut/src/fixture-ids';
import {
	expect,
	type FakePixonaut,
	test,
	usePlainPermalinks,
} from '../fixtures';
import {
	FIXTURE_VARIANT,
	linkSide,
	openPixonautTab,
	openSideMore,
	pick,
	pickVariant,
	productPanel,
	publish,
	viewProduct,
} from '../products';

// The product screen looks the same under every theme.
test.skip(
	({ theme }) => theme !== 'storefront',
	'wp-admin does not depend on the theme',
);

/** The option the tee's print areas change with in these tests. */
const VARIES_BY = 'Size';

/** The tee's front per size: too small on S for the side's placement
 *  default (the template fitted into 300 × 360 mm), large enough on M. */
const FRONT_AREAS = [
	{
		row: { [VARIES_BY]: 'S' },
		widthMm: 220,
		heightMm: 280,
		usualWidthMm: 160,
		usualHeightMm: 200,
	},
	{
		row: { [VARIES_BY]: 'M' },
		widthMm: 330,
		heightMm: 420,
		usualWidthMm: 300,
		usualHeightMm: 360,
	},
];

/** Makes the tee's areas vary by size, then publishes a product with a
 *  standing front on it, and opens its Pixonaut tab again. */
async function fixedTee(page: Page, fake: FakePixonaut): Promise<void> {
	const set = await fake.control.post(
		`/__fake/blanks/${FIXTURE.blank.tee}/areas`,
		{
			data: {
				areasVaryBy: [VARIES_BY],
				areas: { [FIXTURE.placement.front]: FRONT_AREAS },
			},
		},
	);
	expect(set.ok(), "the tee's areas vary by size").toBe(true);

	await page.goto('/wp-admin/post-new.php?post_type=product');
	await page.locator('#title').fill(`Fixed tee ${Date.now()}`);
	await page.locator('#_regular_price').fill('25');
	await openPixonautTab(page);
	const panel = productPanel(page);
	await panel.getByLabel('Personalise').check();
	await pick(page, 'pixonaut_blank', 'tee', 'Fixture tee');
	await linkSide(page, 0, 'Front');
	await openSideMore(page, 0);
	await panel.locator('#pixonaut_side_0_mode').uncheck();
	await publish(page);
	await pickVariant(page, FIXTURE_VARIANT);
	await openPixonautTab(page);
}

test('a standing side on a blank whose print areas vary shows a design per size', async ({
	connectedStore: page,
	fake,
	baseURL,
}) => {
	await usePlainPermalinks(page, baseURL);
	await fixedTee(page, fake);
	const panel = productPanel(page);
	const options = panel.locator('.pixonaut-standing-options');
	await expect(options.locator('label').first()).toHaveText(`Per ${VARIES_BY}`);
	const tiles = options.locator('.pixonaut-standing-option');
	await expect(tiles.locator('strong')).toHaveText(['S', 'M']);
	await expect(tiles.nth(0)).toContainText("Usual size: the side's is too big");
	await expect(tiles.nth(1)).not.toContainText('Usual size');
	// Each preview is the size's own design drawn on the blank.
	for (const image of await tiles.locator('img').all()) {
		await expect(image).toHaveJSProperty('complete', true);
		expect(
			await image.evaluate((img: HTMLImageElement) => img.naturalWidth),
		).toBeGreaterThan(0);
	}
	await options.scrollIntoViewIfNeeded();
	await page.screenshot({
		path: test.info().outputPath('standing-options.png'),
		clip:
			(await panel.locator('.pixonaut-side').first().boundingBox()) ??
			undefined,
	});

	const standing = (await fake.requests()).filter(
		(r) => r.operation === 'POST /v1/designs/standing',
	);
	expect(standing.map((r) => r.status)).toEqual(
		expect.arrayContaining([422, 201, 201]),
	);

	// A change to the side makes new designs on the next update.
	await panel.locator('#pixonaut_side_0_mode').check();
	await expect(options).toBeHidden();
	await panel.locator('#pixonaut_side_0_mode').uncheck();
	await expect(options).toBeVisible();
	await panel
		.getByRole('button', { name: 'Add the print position’s size' })
		.click();
	await expect(
		options.getByText('The previews appear when you update the product.'),
	).toBeVisible();
	await expect(tiles.first()).toBeHidden();

	// The product sells the small design. Leaving drops the unsaved size row.
	page.on('dialog', (dialog) => dialog.accept());
	await page.reload();
	await viewProduct(page);
	await expect(page.locator('button[name="add-to-cart"]')).toBeVisible();
});

/** Opens the Pixonaut tab of a product screen just loaded, once
 *  WooCommerce's tabs answer a click. */
async function openPanel(page: Page): Promise<void> {
	await expect(async () => {
		await openPixonautTab(page);
		await expect(productPanel(page)).toBeVisible({ timeout: 1000 });
	}).toPass();
}

test('one size of a standing side is laid out by hand, kept, and reset', async ({
	connectedStore: page,
	fake,
	baseURL,
}) => {
	await usePlainPermalinks(page, baseURL);
	await fixedTee(page, fake);
	const tiles = productPanel(page).locator('.pixonaut-standing-option');
	const small = tiles.filter({
		has: page.locator('strong', { hasText: /^S$/ }),
	});

	// Pixonaut's hosted page opens the size's design for its layout alone.
	await small.getByRole('link', { name: 'Adjust layout' }).click();
	await expect(page.getByText('Layout', { exact: true })).toBeVisible();
	await page.getByRole('button', { name: 'Approve design' }).click();

	await expect(page.locator('.notice-success')).toContainText(
		'The layout of S is saved.',
	);
	await openPanel(page);
	await expect(small.locator('.pixonaut-standing-option__adjusted')).toHaveText(
		'Adjusted',
	);
	await expect(
		tiles.filter({
			hasNot: page.locator('.pixonaut-standing-option__adjusted'),
		}),
	).toHaveCount(1);
	const adjusted = (await fake.requests()).filter(
		(r) => r.operation === 'POST /v1/designs/standing' && r.status === 201,
	);
	expect(adjusted.length, 'S, M, then the adjusted S').toBe(3);
	await small.scrollIntoViewIfNeeded();
	await page.screenshot({
		path: test.info().outputPath('standing-options-adjusted.png'),
		clip:
			(await productPanel(page)
				.locator('.pixonaut-standing-options')
				.boundingBox()) ?? undefined,
	});

	// Reset asks first, then S follows the side again.
	page.once('dialog', (dialog) => dialog.accept());
	await small.getByRole('link', { name: 'Reset' }).click();
	await expect(page.locator('.notice-success')).toContainText(
		'S follows the side again.',
	);
	await openPanel(page);
	await expect(
		tiles.locator('.pixonaut-standing-option__adjusted'),
	).toHaveCount(0);
	await expect(
		small.getByRole('link', { name: 'Adjust layout' }),
	).toBeVisible();
});
