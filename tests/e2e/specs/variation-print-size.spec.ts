import type { Locator, Page } from '@playwright/test';
import { FIXTURE } from '../../fake-pixonaut/src/fixture-ids';
import { expect, test } from '../fixtures';
import { createVariableProduct, PERSONALISED } from '../personalised';
import { openPixonautTab } from '../products';

// The product screen looks the same under every theme.
test.skip(
	({ theme }) => theme !== 'storefront',
	'wp-admin does not depend on the theme',
);

/** The option the tee's print areas change with in this test. */
const VARIES_BY = 'Size';

/** The tee's front per size. The mini tee's 280:360 artwork fitted into
 *  S's usual box is 155.6 × 200 mm; nothing larger than 330 × 420 mm prints
 *  on an M. */
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

/** The fixture's product with its front sold at the placement's default. */
const AT_DEFAULT = {
	...PERSONALISED,
	sides: PERSONALISED.sides.map((side) => ({
		...side,
		printSizes: [
			{
				widthMm: 280,
				heightMm: 360,
				label: '280 × 360 mm',
				placementDefault: true,
			},
		],
	})),
};

/** The variation of a size, in the Variations tab, opened. */
async function variation(page: Page, size: string): Promise<Locator> {
	const row = page.locator('.woocommerce_variation', {
		has: page.locator(
			`select[name^="attribute_size"] option[value="${size}"]:checked`,
		),
	});
	await row.locator('h3 strong').first().click();
	return row.locator('.pixonaut-variation-panel');
}

/** A variation panel's print sizes, apart from its override's own fields. */
function sizes(panel: Locator): Locator {
	return panel.locator('.pixonaut-variation-sizes');
}

async function openVariations(page: Page, id: number): Promise<void> {
	await page.goto(`/wp-admin/post.php?post=${id}&action=edit`);
	await page.locator('.variations_tab a').click();
}

/** Saves the Variations tab and waits for WooCommerce's answer. */
async function saveVariations(page: Page): Promise<void> {
	const saved = page.waitForResponse(
		(response) =>
			response.url().includes('admin-ajax.php') &&
			(response.request().postData() ?? '').includes(
				'woocommerce_save_variations',
			),
	);
	await page.getByRole('button', { name: 'Save changes' }).click();
	expect((await saved).ok()).toBe(true);
}

test("a variation prints at its blank variant's area, or at a size of its own kept as entered", async ({
	connectedStore: page,
	request,
	fake,
}) => {
	// Two saves of the variations and two of the product, each read back.
	test.slow();
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
	const id = await createVariableProduct(
		request,
		`Sized tee ${Date.now()}`,
		AT_DEFAULT,
		['S', 'M'],
	);

	// The product is checked on the blank whose areas vary.
	await page.goto(`/wp-admin/post.php?post=${id}&action=edit`);
	await openPixonautTab(page);
	await page.locator('#publish').click();
	await page.waitForURL(/post\.php\?post=\d+&action=edit/);

	// Each variation is paired with its variant; the small one gets a size of its own, the medium one one too large.
	await openVariations(page, id);
	const small = await variation(page, 'S');
	await small
		.getByLabel('Variant', { exact: true })
		.selectOption('Color: White, Size: S');
	await expect(small.locator('.pixonaut-variation-size-custom')).toBeHidden();
	await sizes(small)
		.getByLabel('Print size: Front')
		.selectOption('Custom size for this variation');
	await sizes(small).getByLabel('Width in millimetres').fill('140');
	await sizes(small).getByLabel('Height in millimetres').fill('180');
	const medium = await variation(page, 'M');
	await medium
		.getByLabel('Variant', { exact: true })
		.selectOption('Color: White, Size: M');
	await sizes(medium)
		.getByLabel('Print size: Front')
		.selectOption('Custom size for this variation');
	await sizes(medium).getByLabel('Width in millimetres').fill('400');
	await sizes(medium).getByLabel('Height in millimetres').fill('500');
	await saveVariations(page);

	const expectKept = async () => {
		await openVariations(page, id);
		const smallAgain = await variation(page, 'S');
		await expect(sizes(smallAgain).getByLabel('Print size: Front')).toHaveValue(
			'custom',
		);
		await expect(
			sizes(smallAgain).getByLabel('Width in millimetres'),
		).toHaveValue('140');
		await expect(
			smallAgain.locator('.pixonaut-variation-size-error'),
		).toHaveCount(0);
		// Without its own size it would print at its variant's area.
		await expect(
			smallAgain.locator(
				'.pixonaut-variation-size-kind option[value="product"]',
			),
		).toHaveText('As the product (155.6 × 200 mm)');
		const mediumAgain = await variation(page, 'M');
		await expect(
			sizes(mediumAgain).getByLabel('Width in millimetres'),
		).toHaveValue('400');
		await expect(
			sizes(mediumAgain).getByLabel('Height in millimetres'),
		).toHaveValue('500');
		await expect(
			mediumAgain.locator('.pixonaut-variation-size-error'),
		).toHaveText(
			'The print size is larger than the print position can print. Enter a smaller size, or choose the print position’s size, in the variation’s Pixonaut fields.',
		);
		// Overriding the rest of the setup was not needed.
		await expect(
			mediumAgain.getByLabel('Override for this variation'),
		).not.toBeChecked();
	};
	await expectKept();

	// Updating the whole product keeps both.
	await page.locator('#publish').click();
	await page.waitForURL(/post\.php\?post=\d+&action=edit/);
	await expectKept();
});
