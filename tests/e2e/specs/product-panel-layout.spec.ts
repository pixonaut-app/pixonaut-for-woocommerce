import { expect, test, usePlainPermalinks } from '../fixtures';
import {
	linkSide,
	openPixonautTab,
	openStoreSettings,
	openViews,
	pick,
	productPanel,
	publish,
} from '../products';

// The product screen looks the same under every theme.
test.skip(
	({ theme }) => theme !== 'storefront',
	'wp-admin does not depend on the theme',
);

/** The width the issue's screenshots were taken at. */
const SCREEN = { width: 1280, height: 900 };

/** Opens a new product linked to the fixture tee's front, saved, at `SCREEN`. */
async function linkedTee(
	page: Parameters<typeof productPanel>[0],
	baseURL: string | undefined,
) {
	await usePlainPermalinks(page, baseURL);
	await page.setViewportSize(SCREEN);
	await page.goto('/wp-admin/post-new.php?post_type=product');
	await page.locator('#title').fill(`Panel tee ${Date.now()}`);
	await page.locator('#_regular_price').fill('30');
	await openPixonautTab(page);
	const panel = productPanel(page);
	await panel.getByLabel('Personalise').check();
	await pick(page, 'pixonaut_blank', 'tee', 'Fixture tee');
	await linkSide(page, 0, 'Front');
	await publish(page);
	await openPixonautTab(page);
	return panel;
}

test('lines each label up with its value and puts the help under the field', async ({
	connectedStore: page,
	baseURL,
}) => {
	const panel = await linkedTee(page, baseURL);
	await expect(panel.locator('.pixonaut-printshop-line')).toBeVisible();

	const rows = await panel.locator('.form-field:visible').evaluateAll((all) =>
		all.flatMap((row) => {
			const label = row.querySelector(':scope > label');
			const value = [...row.children].find(
				(child) =>
					child !== label &&
					child instanceof HTMLElement &&
					child.offsetParent !== null &&
					!child.classList.contains('description'),
			);
			if (!label || !value) {
				return [];
			}
			const l = label.getBoundingClientRect();
			const v = value.getBoundingClientRect();
			const help = [
				...row.querySelectorAll<HTMLElement>(':scope > .description'),
			].filter((d) => d.offsetParent !== null);
			return [
				{
					label: label.textContent?.trim() ?? '',
					// The label's middle within the value's first line (or its box).
					aligned:
						l.top + l.height / 2 >= v.top &&
						l.top + l.height / 2 <= v.top + Math.min(v.height, 48),
					sameLine: l.right <= v.left,
					helpBelow: help.every(
						(d) => d.getBoundingClientRect().top >= v.bottom - 1,
					),
				},
			];
		}),
	);

	expect(rows.map((row) => row.label)).toEqual(
		expect.arrayContaining(['Printed on', 'Variant', 'Print position *']),
	);
	for (const row of rows) {
		expect(row, row.label).toMatchObject({
			aligned: true,
			sameLine: true,
			helpBelow: true,
		});
	}
});

test('shows what depends on the blank as soon as it is picked', async ({
	connectedStore: page,
	baseURL,
}) => {
	await usePlainPermalinks(page, baseURL);
	await page.setViewportSize(SCREEN);
	await page.goto('/wp-admin/post-new.php?post_type=product');
	await openPixonautTab(page);
	const panel = productPanel(page);
	await panel.getByLabel('Personalise').check();
	// Without a blank, only printshops that print without one are offered.
	await expect(
		panel.getByLabel('Printshop', { exact: true }).locator('option'),
	).not.toContainText(['Gelato']);

	await pick(page, 'pixonaut_blank', 'tee', 'Fixture tee');

	await expect(panel.locator('.pixonaut-printshop-name')).toHaveText(
		'Fixture Print AB',
	);
	await expect(panel.getByLabel('Variant', { exact: true })).toBeVisible();
	await openViews(page);
	await expect(
		panel.locator('.pixonaut-views__list input[type=checkbox]').first(),
	).toBeEnabled();
	// The tab carries Pixonaut's mark, not WooCommerce's wrench.
	const mark = await page
		.locator('li.pixonaut_options a')
		.evaluate((link) => getComputedStyle(link, '::before').maskImage);
	expect(mark).toContain('data:image/svg+xml');
});

test('keeps what a merchant rarely changes in closed sections at the end', async ({
	connectedStore: page,
	baseURL,
}) => {
	const panel = await linkedTee(page, baseURL);

	// One side sits in the group as it is, without a card.
	await expect(
		panel.locator('.pixonaut-sides-group h4 > span:visible'),
	).toHaveText('Template and print sizes');
	await expect(panel.locator('.pixonaut-side__header')).toBeHidden();
	const more = panel.locator('.pixonaut-side-more');
	await expect(more).not.toHaveAttribute('open');
	await expect(more.locator('summary')).toContainText(
		'Customers fill in · follows new template versions',
	);

	const views = panel.locator('details.pixonaut-views-section');
	await expect(views).not.toHaveAttribute('open');
	await expect(views.locator('summary')).toContainText('Every view');
	const store = panel.locator('details.pixonaut-store-settings');
	await expect(store).not.toHaveAttribute('open');
	await expect(store.locator('summary')).toContainText(
		'Follows the store’s settings',
	);

	await openStoreSettings(page);
	await expect(store.locator('.form-field > label')).toHaveText([
		'Layout',
		'Form layout',
		'Options in the form',
		'Group numbers',
		'When a print size has other proportions',
		'Approval',
	]);
	await store.getByLabel('Approval').selectOption({ index: 1 });
	await expect(store.locator('summary')).toContainText(
		'1 changed for this product',
	);
	await publish(page);

	// A changed section opens by itself.
	await openPixonautTab(page);
	await expect(store).toHaveAttribute('open');
	await openViews(page);
	await expect(views.locator('.form-field > label')).toHaveText([
		'Shown in',
		'Starting view',
	]);
});

test('turns one side into cards once a second is added', async ({
	connectedStore: page,
	baseURL,
}) => {
	const panel = await linkedTee(page, baseURL);
	await panel.getByRole('button', { name: 'Add side' }).click();

	await expect(
		panel.locator('.pixonaut-sides-group h4 > span:visible'),
	).toHaveText('Sides');
	await expect(panel.locator('.pixonaut-side__name')).toHaveText([
		'Front',
		'Side',
	]);
	await linkSide(page, 1, 'Back');
	await expect(panel.locator('.pixonaut-side__name').last()).toHaveText('Back');

	await panel
		.locator('.pixonaut-side[data-index="1"]')
		.getByRole('button', { name: 'Remove side' })
		.click();
	await expect(panel.locator('.pixonaut-side__header')).toBeHidden();
});

test('shows a custom size the placement’s largest print and refuses one past it', async ({
	connectedStore: page,
	baseURL,
}) => {
	const panel = await linkedTee(page, baseURL);
	const front = panel.locator('.pixonaut-side[data-index="0"]');
	await front.getByRole('button', { name: 'Add a size in mm' }).click();
	const size = front.locator('.pixonaut-size').last();

	// The fixture tee's front prints at most 330 × 420 mm.
	await expect(size.locator('.pixonaut-size-limit')).toHaveText(
		'Up to 330 × 420 mm',
	);
	await size.getByLabel('Width in millimetres').fill('400');
	await size.getByLabel('Height in millimetres').fill('250');
	await size.getByLabel('Height in millimetres').blur();
	await expect(size.getByRole('alert')).toHaveText(
		'This print position prints at most 330 × 420 mm.',
	);
	await expect(size.getByLabel('Width in millimetres')).toHaveAttribute(
		'aria-invalid',
		'true',
	);

	await size.getByLabel('Width in millimetres').fill('300');
	await size.getByLabel('Width in millimetres').blur();
	await expect(size.getByRole('alert')).toHaveCount(0);

	// The print position's own size says nothing of a limit.
	await expect(
		front.locator('.pixonaut-size').first().locator('.pixonaut-size-limit'),
	).toBeHidden();
});
