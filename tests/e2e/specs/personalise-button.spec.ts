import type { Page } from '@playwright/test';
import {
	expect,
	type FakePixonaut,
	test,
	usePlainPermalinks,
} from '../fixtures';
import { type Line, openCheckouts, pay, readOrder, runJobs } from '../orders';
import { publishLinkedProduct } from '../products';

/** The button layout's name in the product panel. */
const BUTTON_LAYOUT = 'Personalise button: opens the Pixonaut designer';

/** The statuses the fake answered an operation with, in order. */
async function statuses(
	fake: FakePixonaut,
	operation: string,
): Promise<number[]> {
	return (await fake.requests())
		.filter((r) => r.operation === operation)
		.map((r) => r.status);
}

/** The published product's address, from its edit screen. */
async function permalinkOf(admin: Page): Promise<string> {
	const permalink = await admin
		.locator('#wp-admin-bar-view a, #view-post-btn a')
		.first()
		.getAttribute('href');
	expect(permalink).toBeTruthy();
	return permalink ?? '';
}

/** Presses Personalise and waits for the fake's hosted designer. */
async function openDesigner(customer: Page, fake: FakePixonaut): Promise<void> {
	const form = customer.locator('form.cart');
	await expect(form.getByRole('button', { name: /add to cart/i })).toBeHidden();
	await form.getByRole('button', { name: 'Personalise' }).click();
	await customer.waitForURL(
		(url) => url.origin === new URL(fake.baseURL).origin,
	);
	await expect(
		customer.getByRole('heading', { name: 'Pixonaut designer' }),
	).toBeVisible();
}

test('the Personalise button opens the hosted designer and adds the approved design to the cart', async ({
	connectedStore: admin,
	customer,
	fake,
	baseURL,
	theme,
}) => {
	test.slow();
	await usePlainPermalinks(admin, baseURL);
	await publishLinkedProduct(admin, `Button tee ${theme} ${Date.now()}`, '25', {
		layout: BUTTON_LAYOUT,
	});
	const permalink = await permalinkOf(admin);

	// Cancelling in the designer returns to the product with nothing added.
	await customer.goto(permalink);
	await openDesigner(customer, fake);
	await customer.getByRole('link', { name: 'Cancel' }).click();
	await customer.waitForURL(permalink);
	expect(await statuses(fake, 'POST /v1/designs')).toEqual([]);

	// Approving it adds the product to the cart.
	await openDesigner(customer, fake);
	await customer.getByRole('link', { name: /^Approve and / }).click();
	await expect(customer.getByText(/This is your design/)).toBeVisible();
	await customer
		.locator('form.cart, form[data-wp-interactive]')
		.getByRole('button', { name: /add to cart/i })
		.click();
	await expect(customer.getByText(/has been added to your cart/)).toBeVisible();
	// One link, minted when the product was saved, serves both trips.
	expect(
		await statuses(fake, 'POST /v1/templates/{id}/designer-links'),
	).toEqual([201]);
	expect(await statuses(fake, 'POST /v1/designs')).toEqual([201]);
});

test('front and back from one designer link make one cart line and one printshop order', async ({
	connectedStore: admin,
	customer,
	fake,
	request,
	baseURL,
	checkout,
	theme,
}) => {
	test.slow();
	const checkoutUrls = await openCheckouts(request);
	await usePlainPermalinks(admin, baseURL);
	await publishLinkedProduct(
		admin,
		`Two-sided button tee ${theme} ${Date.now()}`,
		'30',
		{ layout: BUTTON_LAYOUT, back: true },
	);

	await customer.goto(await permalinkOf(admin));
	await openDesigner(customer, fake);
	await customer.getByRole('link', { name: /^Approve and / }).click();
	await customer
		.locator('form.cart, form[data-wp-interactive]')
		.getByRole('button', { name: /add to cart/i })
		.click();
	await expect(customer.getByText(/has been added to your cart/)).toBeVisible();
	expect(
		await statuses(fake, 'POST /v1/templates/{id}/designer-links'),
	).toEqual([201]);
	expect(await statuses(fake, 'POST /v1/designs')).toEqual([201, 201]);

	const orderId = await pay(customer, checkout, checkoutUrls);
	await runJobs(request);
	const { lines } = await readOrder(request, orderId);
	expect(lines).toHaveLength(1);
	const [line] = lines as [Line];
	expect(Object.keys(line.sides).sort()).toEqual(['back', 'front']);
	expect(line.state).toBe('ordered');
	expect(await statuses(fake, 'POST /v1/orders')).toEqual([201]);
});
