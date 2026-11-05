import type { Locator, Page } from '@playwright/test';
import {
	expect,
	type FakePixonaut,
	restUrl,
	TESTS_SITE_ON_NETWORK,
	test,
	usePlainPermalinks,
	WEBHOOK_ROUTE,
} from '../fixtures';
import {
	type Line,
	openCheckouts,
	pay,
	readOrder,
	runJobs,
	statuses,
} from '../orders';
import { publishLinkedProduct } from '../products';

/** The button layout's name in the product panel. */
const BUTTON_LAYOUT = 'Personalise button: opens the Pixonaut designer';

/** The proof-by-email approval mode's name in the product panel. */
const PROOF_EMAIL_MODE = 'Proof by email after payment';

/** A proof page's path on the fake, which stands in for Pixonaut's. */
const PROOF_PAGE = /\/h\/proof\/[A-Za-z0-9_-]+/;

/** The hosted correction page's path on the fake. */
const CORRECTION_PAGE = /\/h\/correct\/[0-9a-f]+$/;

/** The wording the customer corrects the design to. */
const CORRECTED = 'Corrected on the hosted page';

/** The published product's address, from its edit screen. */
async function permalinkOf(admin: Page): Promise<string> {
	const permalink = await admin
		.locator('#wp-admin-bar-view a, #view-post-btn a')
		.first()
		.getAttribute('href');
	expect(permalink).toBeTruthy();
	return permalink ?? '';
}

/** Personalises the product on the hosted designer and adds it to the cart. */
async function personaliseOnHostedDesigner(
	customer: Page,
	permalink: string,
): Promise<void> {
	await customer.goto(permalink);
	await customer
		.locator('form.cart')
		.getByRole('button', { name: 'Personalise' })
		.click();
	await customer.getByRole('link', { name: /^Approve and / }).click();
	await customer
		.locator('form.cart, form[data-wp-interactive]')
		.getByRole('button', { name: /add to cart/i })
		.click();
	await expect(customer.getByText(/has been added to your cart/)).toBeVisible();
}

/** Writes the correction on the fake's hosted correction page and approves it. */
async function correctOnHostedPage(
	customer: Page,
	fake: FakePixonaut,
): Promise<void> {
	await customer.waitForURL(
		(url) =>
			url.origin === new URL(fake.baseURL).origin &&
			CORRECTION_PAGE.test(url.pathname),
	);
	await customer.getByRole('textbox', { name: 'Text' }).fill(CORRECTED);
	await customer.getByRole('button', { name: 'Approve design' }).click();
}

/** The link changing a waiting design on its product page. */
function changeLink(page: Page): Locator {
	return page.getByRole('link', { name: 'Change design', exact: true });
}

/** The personalised line and its image in the classic cart or the Cart block. */
function cartLine(page: Page): {
	line: Locator;
	image: Locator;
	name: Locator;
} {
	const classic = page.locator('.woocommerce-cart-form .cart_item');
	const block = page.locator('.wc-block-cart-items__row').first();
	const line = classic.or(block);
	return {
		line,
		image: line.locator(
			'img.pixonaut-proof-thumbnail, .wc-block-cart-item__image img',
		),
		// The line's name links to its product page, showing its design.
		name: line.locator('.product-name a, a.wc-block-components-product-name'),
	};
}

test('a button product is corrected from its product page on the hosted correction page, and its cart line with it', async ({
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
	await publishLinkedProduct(
		admin,
		`Corrected button tee ${theme} ${Date.now()}`,
		'25',
		{ layout: BUTTON_LAYOUT },
	);
	await personaliseOnHostedDesigner(customer, await permalinkOf(admin));

	await customer.goto(cartUrls[checkout]);
	const { line, image, name } = cartLine(customer);
	await expect(image).toHaveAttribute('src', /.+/);
	const before = (await image.getAttribute('src')) ?? '';

	// Cancelling on the hosted page changes nothing.
	await name.click();
	await changeLink(customer).click();
	await customer.waitForURL((url) => CORRECTION_PAGE.test(url.pathname));
	await customer.getByRole('link', { name: 'Cancel' }).click();
	await customer.waitForURL((url) => !CORRECTION_PAGE.test(url.pathname));
	expect(await statuses(fake, 'PATCH /v1/designs/{id}')).toEqual([]);

	await changeLink(customer).click();
	await correctOnHostedPage(customer, fake);

	await expect(
		customer.getByText('Your design has been updated.'),
	).toBeVisible();
	await customer.goto(cartUrls[checkout]);
	await expect(image).not.toHaveAttribute('src', before);
	await expect(line).toHaveCount(1);
	expect(
		await statuses(fake, 'POST /v1/designs/{id}/correction-links'),
	).toEqual([201, 201]);
	expect(await statuses(fake, 'PATCH /v1/designs/{id}')).toEqual([200]);
	const mint = (await fake.requests()).find(
		(r) => r.operation === 'POST /v1/designs/{id}/correction-links',
	);
	const designId = /\/v1\/designs\/([^/]+)\//.exec(mint?.path ?? '')?.[1];
	const design = await fake.control.get(`/__fake/designs/${designId}`);
	expect(await design.json()).toMatchObject({ revision: 2 });
});

test('a button product whose proof the customer asked to change is corrected on the hosted page and approved', async ({
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
	// The fake delivers webhooks over the Docker network, where the tests
	// site is not at its browser address.
	const endpoint = await fake.control.post('/__fake/webhooks/endpoint', {
		data: { url: `${TESTS_SITE_ON_NETWORK}${restUrl(WEBHOOK_ROUTE)}` },
	});
	expect(endpoint.ok()).toBe(true);
	await usePlainPermalinks(admin, baseURL);
	await publishLinkedProduct(
		admin,
		`Changes button tee ${theme} ${Date.now()}`,
		'25',
		{ layout: BUTTON_LAYOUT, approval: PROOF_EMAIL_MODE },
	);
	await personaliseOnHostedDesigner(customer, await permalinkOf(admin));
	const orderId = await pay(customer, checkout, checkoutUrls);
	await runJobs(request);
	const [waiting] = (await readOrder(request, orderId)).lines as [Line];
	expect(waiting.state).toBe('awaiting_customer');

	// On the emailed proof page the customer asks for changes …
	const mails = await request.get(restUrl('/pixonaut-e2e/v1/mails'));
	const proofMail = (
		(await mails.json()) as { subject: string; message: string }[]
	).find(
		(mail) => mail.subject.includes(orderId) && PROOF_PAGE.test(mail.message),
	);
	const path = PROOF_PAGE.exec(proofMail?.message ?? '')?.[0];
	expect(path, 'the email links the proof page').toBeTruthy();
	await customer.goto(new URL(path ?? '', fake.baseURL).toString());
	await customer
		.getByRole('textbox', { name: 'What should change?' })
		.fill('Please change the wording.');
	await customer.getByRole('button', { name: 'Request changes' }).click();

	// … and makes them on the hosted correction page, which approves them.
	await correctOnHostedPage(customer, fake);
	await customer.waitForURL(/order-received/);
	await expect(
		customer.getByText('Your design has been updated.'),
	).toBeVisible();

	await runJobs(request);
	await runJobs(request);
	const [ordered] = (await readOrder(request, orderId)).lines as [Line];
	expect(ordered.state).toBe('ordered');
	expect(await statuses(fake, 'PATCH /v1/designs/{id}')).toEqual([200]);
	expect(await statuses(fake, 'POST /v1/designs/{id}/approve')).toEqual([200]);
	const [side] = Object.values(ordered.sides);
	const design = await fake.control.get(`/__fake/designs/${side?.designId}`);
	expect(await design.json()).toMatchObject({
		status: 'approved',
		revision: 2,
		consent: { kind: 'edit' },
	});
});
