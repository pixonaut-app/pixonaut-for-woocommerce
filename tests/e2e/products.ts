import type { Page } from '@playwright/test';

/** Picks `option` in a selectWoo field after typing `search` into it. */
export async function pick(
	page: Page,
	field: string,
	search: string,
	option: string,
): Promise<void> {
	await page.locator(`#${field} + .select2-container`).click();
	await page
		.locator(`.select2-search__field[aria-owns="select2-${field}-results"]`)
		.fill(search);
	await page
		.locator(`#select2-${field}-results .select2-results__option`, {
			hasText: option,
		})
		.first()
		.click();
}

/** The fixture tee's variant a linked product sells unless told otherwise. */
export const FIXTURE_VARIANT = 'Color: White, Size: S';

/** The product screen's Pixonaut panel. */
export function productPanel(page: Page) {
	return page.locator('.pixonaut-product-panel');
}

/** Opens the product screen's Pixonaut tab. */
export async function openPixonautTab(page: Page): Promise<void> {
	await page.locator('.pixonaut_options a').click();
}

/** Publishes a simple product from wp-admin, personalised on the fixture
 *  tee's front with the fixture's T-shirt template, and waits for its edit
 *  screen. `approval` and `layout` pick the product's approval mode and
 *  layout by their names in the panel; without them the product follows the
 *  store's defaults. With `back`, the tee's back is a second side, named
 *  "Back". It sells the tee's `variant`, white in small by default. */
export async function publishLinkedProduct(
	page: Page,
	name: string,
	price: string,
	{
		approval,
		layout,
		back = false,
		variant = FIXTURE_VARIANT,
	}: {
		approval?: string;
		layout?: string;
		back?: boolean;
		variant?: string;
	} = {},
): Promise<void> {
	await page.goto('/wp-admin/post-new.php?post_type=product');
	await page.locator('#title').fill(name);
	await page.locator('#_regular_price').fill(price);
	await openPixonautTab(page);
	const panel = productPanel(page);
	await panel.getByLabel('Personalise').check();
	await pick(page, 'pixonaut_blank', 'tee', 'Fixture tee');
	await linkSide(page, 0, 'Front');
	if (back) {
		await openSideMore(page, 0);
		await panel.locator('#pixonaut_side_0_label').fill('Front');
		await panel.getByRole('button', { name: 'Add side' }).click();
		await linkSide(page, 1, 'Back');
		await openSideMore(page, 1);
		await panel.locator('#pixonaut_side_1_label').fill('Back');
	}
	if (approval !== undefined || layout !== undefined) {
		await openStoreSettings(page);
	}
	if (approval !== undefined) {
		await panel.getByLabel('Approval').selectOption({ label: approval });
	}
	if (layout !== undefined) {
		await panel
			.getByLabel('Layout', { exact: true })
			.selectOption({ label: layout });
	}
	await publish(page);
	await pickVariant(page, variant);
}

/** Picks the blank variant the product sells in its Pixonaut tab, which
 *  shows once the product is saved on a blank, and updates the product. */
export async function pickVariant(page: Page, variant: string): Promise<void> {
	await openPixonautTab(page);
	await productPanel(page)
		.getByLabel('Variant', { exact: true })
		.selectOption({ label: variant });
	await publish(page);
}

/** Opens a closed section of the Pixonaut tab, the one `section` finds. */
async function open(page: Page, section: string): Promise<void> {
	const details = productPanel(page).locator(section);
	if ((await details.getAttribute('open')) === null) {
		await details.locator(':scope > summary').click();
	}
}

/** Opens the tab's Store settings for this product. */
export async function openStoreSettings(page: Page): Promise<void> {
	await open(page, 'details.pixonaut-store-settings');
}

/** Opens the tab's Views on the product page. */
export async function openViews(page: Page): Promise<void> {
	await open(page, 'details.pixonaut-views-section');
}

/** Opens More for this side of side `index`. */
export async function openSideMore(page: Page, index: number): Promise<void> {
	await open(
		page,
		`.pixonaut-side[data-index="${index}"] details.pixonaut-side-more`,
	);
}

/** Sets side `index` of the Pixonaut tab to `placement` with the fixture's
 *  T-shirt template. */
export async function linkSide(
	page: Page,
	index: number,
	placement: string,
): Promise<void> {
	await productPanel(page)
		.locator(`#pixonaut_side_${index}_placement`)
		.selectOption(placement);
	await pick(
		page,
		`pixonaut_side_${index}_template`,
		'shirt',
		'T-shirt med tryck',
	);
}

/** Publishes (or updates) the product and waits for its edit screen. */
export async function publish(page: Page): Promise<void> {
	await page.locator('#publish').click();
	await page.waitForURL(/post\.php\?post=\d+&action=edit/);
}

/** The published product's page, from its edit screen. */
export async function viewProduct(page: Page): Promise<void> {
	await page.locator('#wp-admin-bar-view a, #view-post-btn a').first().click();
}
