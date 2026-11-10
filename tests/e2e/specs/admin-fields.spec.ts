import { expect, settingsPath, test, usePlainPermalinks } from '../fixtures';
import { openPixonautTab, pick, productPanel, publish } from '../products';

/** The route the placement field's pictures come from. */
const PLACEMENTS_ROUTE = /admin(%2F|\/)search(%2F|\/)placements/;

// The admin looks the same under every theme.
test.skip(
	({ theme }) => theme !== 'storefront',
	'wp-admin does not depend on the theme',
);

test('shows the configuration once Personalise is ticked, pictures in its pickers and what is missing', async ({
	connectedStore: page,
	baseURL,
}) => {
	await usePlainPermalinks(page, baseURL);
	await page.goto('/wp-admin/post-new.php?post_type=product');
	await page.locator('#title').fill(`Grouped tee ${Date.now()}`);
	await openPixonautTab(page);
	const panel = productPanel(page);

	await expect(panel.getByRole('heading', { name: 'Printing' })).toBeHidden();
	await panel.getByLabel('Personalise').check();
	await expect(panel.getByRole('heading', { name: 'Printing' })).toBeVisible();
	await expect(
		panel.getByRole('heading', { name: 'Template and print sizes' }),
	).toBeVisible();

	// The blank picker shows each blank's photo beside its name.
	await page.locator('#pixonaut_blank + .select2-container').click();
	await page
		.locator(
			'.select2-search__field[aria-owns="select2-pixonaut_blank-results"]',
		)
		.fill('tee');
	const tee = page.locator(
		'#select2-pixonaut_blank-results .select2-results__option',
		{
			hasText: 'Fixture tee',
		},
	);
	await expect(tee.locator('img.pixonaut-thumbnail')).toHaveAttribute(
		'src',
		/\/i\//,
	);
	const pictures = page.waitForResponse(PLACEMENTS_ROUTE);
	await tee.first().click();
	await pictures;

	// The placement picker, once the blank's pictures came, shows each placement's.
	await panel
		.locator('#pixonaut_side_0_placement + .select2-container')
		.click();
	await expect(
		page
			.locator(
				'#select2-pixonaut_side_0_placement-results img.pixonaut-thumbnail',
			)
			.first(),
	).toHaveAttribute('src', /\/i\//);
	await page
		.locator(
			'#select2-pixonaut_side_0_placement-results .select2-results__option',
			{
				hasText: 'Front',
			},
		)
		.first()
		.click();

	// The template picker shows each template's preview.
	await pick(page, 'pixonaut_side_0_template', 'shirt', 'T-shirt med tryck');
	await panel.locator('#pixonaut_side_0_template + .select2-container').click();
	await expect(
		page
			.locator(
				'#select2-pixonaut_side_0_template-results img.pixonaut-thumbnail',
			)
			.first(),
	).toHaveAttribute('src', /\/i\//);
	await page.keyboard.press('Escape');

	// A template cleared is missing; saving says so in place too.
	await panel
		.locator(
			'#pixonaut_side_0_template + .select2-container .select2-selection__clear',
		)
		.click();
	await page.keyboard.press('Escape');
	await expect(
		panel.getByText('Choose a template for this side.'),
	).toBeVisible();
	await publish(page);
	await openPixonautTab(page);
	await expect(panel.locator('.pixonaut-product-invalid')).toContainText(
		'Choose a template.',
	);
	await expect(
		panel.getByText('Choose a template for this side.'),
	).toBeVisible();
});

test('shows the custom accent colour only for a custom accent, and keeps it when saved empty', async ({
	connectedStore: page,
}) => {
	await page.goto(settingsPath('storefront'));
	const accent = page.getByLabel('Designer accent colour', { exact: true });
	const colour = page.locator(
		'[id="pixonaut_wc_settings[designer_accent_colour]"]',
	);

	await accent.selectOption({ label: 'A custom colour' });
	await expect(colour).toBeVisible();
	await colour.fill('#1e73be');
	await page.getByRole('button', { name: 'Save changes' }).click();
	await expect(colour).toHaveValue('#1e73be');

	// Typed, as WooCommerce enables its Save button on a key press.
	await colour.selectText();
	await colour.press('Backspace');
	await colour.blur();
	await expect(
		page
			.getByText(
				'Designer accent colour is set to A custom colour: enter the colour, e.g. #1e73be.',
			)
			.first(),
	).toBeVisible();
	await page.getByRole('button', { name: 'Save changes' }).click();
	await expect(
		page.locator('#message.error, .notice-error').first(),
	).toContainText('enter the colour');
	await expect(colour).toHaveValue('#1e73be');

	await accent.selectOption({
		label:
			"None: the accent of the store's brand in Pixonaut, else the designer's own colours",
	});
	await expect(colour).toBeHidden();
});

test('opens the settings from the Pixonaut menu item, and sends the old WooCommerce tab there', async ({
	connectedStore: page,
}) => {
	const errors: string[] = [];
	// Every wp-admin screen on the tests site throws a bare, nameless object
	// from elsewhere; only real errors count.
	page.on('pageerror', (error) => {
		if (error.name !== '') {
			errors.push(error.message);
		}
	});
	await page.goto('/wp-admin/');
	await page
		.locator('#adminmenu')
		.getByRole('link', { name: 'Pixonaut', exact: true })
		.click();
	await expect(
		page.getByRole('heading', { name: 'Pixonaut settings' }),
	).toBeVisible();
	const tabs = page.locator('.nav-tab-wrapper');
	await expect(tabs.getByRole('link', { name: 'Connection' })).toHaveAttribute(
		'aria-current',
		'page',
	);

	expect(errors).toEqual([]);

	await tabs.getByRole('link', { name: 'Approval' }).click();
	await expect(page.getByLabel('Proof reminders at most')).toBeVisible();

	await page.goto(
		'/wp-admin/admin.php?page=wc-settings&tab=pixonaut&pixonaut_connect=pinged',
	);
	await expect(page).toHaveURL(
		/page=pixonaut&tab=connection&pixonaut_connect=pinged/,
	);

	await page.goto('/wp-admin/admin.php?page=wc-settings');
	await expect(
		page
			.locator('.woo-nav-tab-wrapper')
			.getByRole('link', { name: 'Pixonaut' }),
	).toHaveCount(0);
});
