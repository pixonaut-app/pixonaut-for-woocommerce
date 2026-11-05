import { expect, test, usePlainPermalinks } from '../fixtures';
import {
	linkSide,
	openPixonautTab,
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
	await expect(panel.locator('.pixonaut-printshop')).toBeVisible();

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
		expect.arrayContaining(['Blank', 'Printshop', 'Blank variant', 'Name']),
	);
	for (const row of rows) {
		expect(row, row.label).toMatchObject({
			aligned: true,
			sameLine: true,
			helpBelow: true,
		});
	}
});

test('puts the views at the end of On the product page', async ({
	connectedStore: page,
	baseURL,
}) => {
	const panel = await linkedTee(page, baseURL);

	const group = panel.locator('.pixonaut-group', {
		has: page.locator('#pixonaut_initial_view'),
	});
	await expect(group.locator('.pixonaut-group__title')).toHaveText(
		'On the product page',
	);
	await expect(group.locator(':scope > .form-field > label')).toHaveText([
		'Layout',
		'Form layout',
		'Options in the form',
		'Group numbers',
		'Canvas shape',
		'Approval',
	]);
	await expect(group.locator('.pixonaut-views .form-field > label')).toHaveText(
		['Shown in', 'Starting view'],
	);
});

test('shows a custom size the placement’s largest print and refuses one past it', async ({
	connectedStore: page,
	baseURL,
}) => {
	const panel = await linkedTee(page, baseURL);
	const front = panel.locator('.pixonaut-side[data-index="0"]');
	await front.getByRole('button', { name: 'Add print size' }).click();
	const size = front.locator('.pixonaut-size').last();
	await size
		.getByLabel('Print size', { exact: true })
		.selectOption('Custom size');

	// The fixture tee's front prints at most 330 × 420 mm.
	await expect(size.locator('.pixonaut-size-limit')).toHaveText(
		'Up to 330 × 420 mm',
	);
	await size.getByLabel('Width in millimetres').fill('400');
	await size.getByLabel('Height in millimetres').fill('250');
	await size.getByLabel('Height in millimetres').blur();
	await expect(size.getByRole('alert')).toHaveText(
		'This placement prints at most 330 × 420 mm.',
	);
	await expect(size.getByLabel('Width in millimetres')).toHaveAttribute(
		'aria-invalid',
		'true',
	);

	await size.getByLabel('Width in millimetres').fill('300');
	await size.getByLabel('Width in millimetres').blur();
	await expect(size.getByRole('alert')).toHaveCount(0);

	// Back on the placement's default size, nothing is said of a limit.
	await size
		.getByLabel('Print size', { exact: true })
		.selectOption('Placement default');
	await expect(size.locator('.pixonaut-size-limit')).toBeHidden();
});
