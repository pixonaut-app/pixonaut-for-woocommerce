import type { Locator, Page } from '@playwright/test';
import { FIXTURE } from '../../fake-pixonaut/src/fixture-ids';
import {
	expect,
	type FakePixonaut,
	restUrl,
	test,
	usePlainPermalinks,
} from '../fixtures';
import { connectFixtureStore, PERSONALISED } from '../personalised';
import { publishLinkedProduct } from '../products';

/** The button layout's name in the product panel. */
const BUTTON_LAYOUT = 'Personalise button: opens the Pixonaut designer';

/** A phone's width, where the row and the box must still fit. */
const PHONE = { width: 390, height: 844 };

/** How many the customer buys. */
const QUANTITY = 3;

function form(page: Page): Locator {
	return page.locator('form.cart, form[data-wp-interactive]');
}

function personalise(page: Page): Locator {
	return form(page).getByRole('button', { name: 'Personalise' });
}

function addToCart(page: Page): Locator {
	return form(page).getByRole('button', { name: /add to cart/i });
}

function box(page: Page): Locator {
	return page.locator('.pixonaut-personalised:visible');
}

function inCart(page: Page): Locator {
	return page.locator('.pixonaut-in-cart:visible');
}

function designImage(page: Page): Locator {
	return page.locator('.pixonaut-design-gallery:visible img').first();
}

function quantity(page: Page): Locator {
	return form(page).locator('input.qty');
}

async function permalinkOf(admin: Page): Promise<string> {
	const permalink = await admin
		.locator('#wp-admin-bar-view a, #view-post-btn a')
		.first()
		.getAttribute('href');
	expect(permalink).toBeTruthy();
	return permalink ?? '';
}

async function approveOnHostedDesigner(
	page: Page,
	fake: FakePixonaut,
): Promise<void> {
	await personalise(page).click();
	await page.waitForURL((url) => url.origin === new URL(fake.baseURL).origin);
	await page.getByRole('link', { name: /^Approve and / }).click();
	await page.waitForURL((url) => url.origin !== new URL(fake.baseURL).origin);
}

/** The vertical centre of an element, to compare rows by. */
async function centreY(element: Locator): Promise<number> {
	const bounds = await element.boundingBox();
	expect(bounds).not.toBeNull();
	return (bounds?.y ?? 0) + (bounds?.height ?? 0) / 2;
}

async function expectInOneRow(first: Locator, second: Locator): Promise<void> {
	const [a, b] = await Promise.all([centreY(first), centreY(second)]);
	const SAME_ROW_TOLERANCE_PX = 12;
	expect(Math.abs(a - b)).toBeLessThan(SAME_ROW_TOLERANCE_PX);
}

test('coming back from the hosted page leaves the cart alone and shows the design, ready to add, from desktop to phone', async ({
	connectedStore: admin,
	customer,
	fake,
	request,
	baseURL,
	checkout,
	theme,
}) => {
	test.slow();
	const response = await request.post(restUrl('/pixonaut-e2e/v1/cart'));
	expect(response.ok(), 'the tests site has both carts').toBe(true);
	const cartUrls = (await response.json()) as Record<
		'classic' | 'block',
		string
	>;
	await usePlainPermalinks(admin, baseURL);
	await publishLinkedProduct(admin, `Row tee ${theme} ${Date.now()}`, '25', {
		layout: BUTTON_LAYOUT,
	});
	const permalink = await permalinkOf(admin);

	// The button stands where Add to cart stands, in WooCommerce's classes.
	await customer.goto(permalink);
	await expect(
		customer.locator('form.cart > .single_add_to_cart_button:visible'),
	).toHaveText('Personalise');
	const gallery = await customer
		.locator('.images:visible, .wp-block-woocommerce-product-gallery:visible')
		.first()
		.boundingBox();
	await expect(personalise(customer)).toHaveClass(
		/\bsingle_add_to_cart_button\b.*\bbutton\b.*\balt\b/,
	);
	await expect(addToCart(customer)).toBeHidden();
	await expectInOneRow(quantity(customer), personalise(customer));
	await customer.setViewportSize(PHONE);
	await expectInOneRow(quantity(customer), personalise(customer));
	await customer.setViewportSize({ width: 1280, height: 800 });

	await quantity(customer).fill(String(QUANTITY));
	await approveOnHostedDesigner(customer, fake);

	// Nothing joined the cart; the page shows the design, ready to add.
	await expect(box(customer)).toBeVisible();
	await expect(customer.getByText(/added to your cart/)).toHaveCount(0);
	await expect(personalise(customer)).toBeHidden();
	await expect(addToCart(customer)).toBeVisible();
	await expect(designImage(customer)).toBeVisible();
	await expect(
		customer.locator(
			'.images:not(.pixonaut-design-gallery):visible, .wp-block-woocommerce-product-gallery:visible',
		),
	).toHaveCount(0);
	const WIDTH_TOLERANCE_PX = 2;
	expect(
		Math.abs(
			((await designImage(customer).boundingBox())?.width ?? 0) -
				(gallery?.width ?? 0),
		),
	).toBeLessThan(WIDTH_TOLERANCE_PX);
	await expect(quantity(customer)).toHaveValue(String(QUANTITY));
	await expectInOneRow(quantity(customer), addToCart(customer));
	// The note's button stays quiet: smaller than the row's, on any theme.
	const change = await box(customer).getByRole('link').boundingBox();
	const add = await addToCart(customer).boundingBox();
	expect(change?.height ?? 0).toBeLessThan(add?.height ?? 0);
	const boxBottom = await box(customer).boundingBox();
	const rowTop = await addToCart(customer).boundingBox();
	expect((boxBottom?.y ?? 0) + (boxBottom?.height ?? 0)).toBeLessThanOrEqual(
		rowTop?.y ?? 0,
	);
	await customer.setViewportSize(PHONE);
	await expect(box(customer)).toBeVisible();
	await expectInOneRow(quantity(customer), addToCart(customer));
	await customer.setViewportSize({ width: 1280, height: 800 });
	const returned = customer.url();

	const cart = await customer.context().newPage();
	await cart.goto(cartUrls[checkout]);
	await expect(
		cart.locator('.product-name a, .wc-block-components-product-name'),
	).toHaveCount(0);
	await cart.close();

	// Add to cart adds that design with the chosen quantity and lands on the
	// item's page, which says so and offers nothing that adds another line.
	await addToCart(customer).click();
	await customer.waitForURL(/pixonaut_line=/);
	await expect(customer.getByText(/added to your cart/)).toBeVisible();
	const expectItemPage = async () => {
		await expect(inCart(customer)).toContainText(
			`In your cart · ${QUANTITY} pcs`,
		);
		await expect(
			inCart(customer).getByRole('link', { name: 'Change design' }),
		).toBeVisible();
		await expect(designImage(customer)).toBeVisible();
		await expect(form(customer)).toHaveCount(0);
		await expect(box(customer)).toHaveCount(0);
	};
	await expectItemPage();
	await customer.setViewportSize(PHONE);
	await expectItemPage();
	await customer.setViewportSize({ width: 1280, height: 800 });

	// The address of the return shows the same, now the design is in the cart.
	await customer.goto(returned);
	await expectItemPage();

	// The cart links back to that page, and that page to the cart.
	await customer.goto(cartUrls[checkout]);
	const line = customer.locator(
		'.woocommerce-cart-form .cart_item, .wc-block-cart-items__row',
	);
	await expect(line).toHaveCount(1);
	await line
		.locator('.product-name a, a.wc-block-components-product-name')
		.click();
	await expectItemPage();
	await inCart(customer).getByRole('link', { name: 'View cart' }).click();
	await expect(line).toHaveCount(1);

	// The link to make another opens the plain product page.
	await line
		.locator('.product-name a, a.wc-block-components-product-name')
		.click();
	await inCart(customer)
		.getByRole('link', { name: 'Make another with a new design' })
		.click();
	await expect(personalise(customer)).toBeVisible();
	await expect(inCart(customer)).toHaveCount(0);
	expect(customer.url()).not.toMatch(/pixonaut_(line|design)=/);
});

test('a variation keeps its own design: another shows Personalise, and coming back shows it again', async ({
	page,
	request,
	fake,
	baseURL,
	theme,
}) => {
	test.slow();
	await connectFixtureStore(request, baseURL ?? '');
	const created = await request.post(restUrl('/pixonaut-e2e/v1/products'), {
		data: {
			name: `Row variations ${theme} ${Date.now()}`,
			pixonaut: { ...PERSONALISED, layout: 'button' },
			variations: ['White', 'Black'],
			variants: {
				White: FIXTURE.variant.teeWhiteS,
				Black: FIXTURE.variant.teeBlackS,
			},
		},
	});
	expect(created.status()).toBe(201);
	await page.goto((await created.json()).permalink);

	const size = page.locator('select[name="attribute_size"]');
	await size.selectOption('White');
	await expect(personalise(page)).not.toHaveClass(/\bdisabled\b/);
	await approveOnHostedDesigner(page, fake);

	// The page opens on the variation the design was made for.
	await expect(size).toHaveValue('White');
	await expect(box(page)).toBeVisible();
	await expect(designImage(page)).toBeVisible();
	await expect(personalise(page)).toBeHidden();

	await size.selectOption('Black');
	await expect(box(page)).toHaveCount(0);
	await expect(designImage(page)).toHaveCount(0);
	await expect(personalise(page)).toBeVisible();

	await size.selectOption('White');
	await expect(box(page)).toBeVisible();
	await expect(designImage(page)).toBeVisible();
	await expect(personalise(page)).toBeHidden();

	// Add to cart adds the design of the variation chosen.
	await expect(page.locator('input[name="variation_id"]')).not.toHaveValue('0');
	await expect(addToCart(page)).not.toHaveClass(/\bdisabled\b/);
	await addToCart(page).click();
	await expect(page.getByText(/added to your cart/)).toBeVisible();

	// Its page says which it is, with no selects left to change it in.
	await expect(inCart(page)).toContainText('In your cart · White · 1 pc');
	await expect(designImage(page)).toBeVisible();
	await expect(size).toHaveCount(0);
	await request.delete(restUrl('/pixonaut-e2e/v1/connection'));
});

test('a plain visit is fresh, and the address of a return shows another session nothing', async ({
	connectedStore: admin,
	customer,
	fake,
	baseURL,
	theme,
	browser,
}) => {
	test.slow();
	await usePlainPermalinks(admin, baseURL);
	await publishLinkedProduct(admin, `Fresh tee ${theme} ${Date.now()}`, '25', {
		layout: BUTTON_LAYOUT,
	});
	const permalink = await permalinkOf(admin);
	await customer.goto(permalink);
	await approveOnHostedDesigner(customer, fake);
	await expect(box(customer)).toBeVisible();
	await expect(
		box(customer).getByRole('link', { name: 'Start over' }),
	).toHaveCount(0);
	await expect(
		box(customer).getByRole('link', { name: 'Change' }),
	).toBeVisible();
	const returned = customer.url();

	await customer.goto(permalink);
	await expect(box(customer)).toHaveCount(0);
	await expect(personalise(customer)).toBeVisible();
	await expect(designImage(customer)).toHaveCount(0);

	const stranger = await (await browser.newContext()).newPage();
	await stranger.goto(returned);
	await expect(box(stranger)).toHaveCount(0);
	await expect(designImage(stranger)).toHaveCount(0);
	await stranger.context().close();

	await customer.goto(returned);
	await expect(box(customer)).toBeVisible();
	await expect(designImage(customer)).toBeVisible();
});

test('a return opened in another session waits for its button, and a foreign return in a session with an open trip adds nothing', async ({
	connectedStore: admin,
	customer,
	fake,
	baseURL,
	theme,
	browser,
}) => {
	test.slow();
	await usePlainPermalinks(admin, baseURL);
	await publishLinkedProduct(
		admin,
		`Foreign tee ${theme} ${Date.now()}`,
		'25',
		{
			layout: BUTTON_LAYOUT,
		},
	);
	await customer.goto(await permalinkOf(admin));
	await personalise(customer).click();
	await customer.waitForURL(
		(url) => url.origin === new URL(fake.baseURL).origin,
	);
	const stranger = await (await browser.newContext()).newPage();
	await stranger.goto(customer.url());
	const strangersReturn = stranger.waitForRequest((request) =>
		request.url().includes('proofToken'),
	);
	await stranger.getByRole('link', { name: 'Approve and go back' }).click();
	const foreignReturn = (await strangersReturn).url();
	await stranger.waitForURL(
		(url) => url.origin !== new URL(fake.baseURL).origin,
	);
	await expect(stranger).not.toHaveURL(/pixonaut_design=/);
	await expect(
		stranger.getByRole('button', { name: 'Use my design' }),
	).toBeVisible();
	await expect(addToCart(stranger)).toBeHidden();
	// Both buttons take the whole row, from the quantity to the column's edge.
	const row = await form(stranger).boundingBox();
	const first = await quantity(stranger).boundingBox();
	const last = await personalise(stranger).boundingBox();
	const EDGE_TOLERANCE_PX = 1;
	expect(Math.abs((first?.x ?? 0) - (row?.x ?? 0))).toBeLessThanOrEqual(
		EDGE_TOLERANCE_PX,
	);
	expect(
		Math.abs(
			(last?.x ?? 0) + (last?.width ?? 0) - (row?.x ?? 0) - (row?.width ?? 0),
		),
	).toBeLessThanOrEqual(EDGE_TOLERANCE_PX);
	await stranger.context().close();

	await customer.goto(foreignReturn);
	await customer.waitForURL(/pixonaut_design=/);
	await expect(box(customer)).toBeVisible();
	await expect(designImage(customer)).toBeVisible();
	await expect(addToCart(customer)).toBeVisible();
	await expect(inCart(customer)).toHaveCount(0);
	const cart = await customer
		.context()
		.request.get(restUrl('/wc/store/v1/cart'));
	expect((await cart.json()).items_count).toBe(0);
});
