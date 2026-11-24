import type { Locator, Page } from '@playwright/test';
import { expect, test } from '../fixtures';
import { createLinkedProduct } from '../products';

/** The pages the customer designs the book at. */
const PAGES = 3;

function form(page: Page): Locator {
	return page.locator('form.cart, form[data-wp-interactive]');
}

test('a book approved a page at a time comes back as one design to add, and joins the cart as one line', async ({
	connectedStore: admin,
	customer,
	fake,
	baseURL,
	theme,
}) => {
	const permalink = await createLinkedProduct(
		admin,
		baseURL,
		`Notebook ${theme} ${Date.now()}`,
		{ layout: 'button' },
	);

	await customer.goto(permalink);
	await form(customer).getByRole('button', { name: 'Personalise' }).click();
	await customer.waitForURL(
		(url) => url.origin === new URL(fake.baseURL).origin,
	);
	// The fake approves the side as a book of pages when its approve address asks.
	const approve = new URL(
		(await customer
			.getByRole('link', { name: /^Approve and / })
			.getAttribute('href')) ?? '',
		customer.url(),
	);
	approve.searchParams.set('pages', String(PAGES));
	await customer.goto(approve.toString());
	await customer.waitForURL(
		(url) => url.origin !== new URL(fake.baseURL).origin,
	);

	await expect(
		customer.locator('.pixonaut-personalised:visible'),
	).toBeVisible();
	await expect(customer.locator('.woocommerce-error')).toHaveCount(0);
	await form(customer)
		.getByRole('button', { name: /add to cart/i })
		.click();
	await customer.waitForURL(/pixonaut_line=/);
	await expect(customer.locator('.pixonaut-in-cart:visible')).toContainText(
		'In your cart',
	);
});
