import type { APIRequestContext } from '@playwright/test';
import { expect, restUrl, TESTS_SITE_ON_NETWORK, test } from '../fixtures';
import {
	type Line,
	openCheckouts,
	pay,
	readOrder,
	runJobs,
	statuses,
} from '../orders';
import { createLinkedProduct } from '../products';

/** The plugin's webhook receiver. */
const WEBHOOK_ROUTE = '/pixonaut/v1/webhook';

/** What the customer first writes on the fixture tee's text layer, and what
 *  they change it to. */
const SLOGAN = 'Firts draft';
const CORRECTED = 'First draft';

/** What the customer asks on the proof page. */
const REQUEST = 'Please fix the typo in the slogan.';

/** A proof page's path on the fake, which stands in for Pixonaut's. */
const PROOF_PAGE = /\/h\/proof\/[A-Za-z0-9_-]+/;

/** The query argument of a product page opened to edit an order line. */
const ORDER_EDIT = /pixonaut_order_edit=/;

type Mail = { to: string[]; subject: string; message: string };

/** The emails the tests site sent whose subject names the order, to the
 *  customer or the store. */
async function mailsFor(
	request: APIRequestContext,
	orderId: string,
): Promise<Mail[]> {
	const response = await request.get(restUrl('/pixonaut-e2e/v1/mails'));
	expect(response.ok()).toBe(true);
	return ((await response.json()) as Mail[]).filter((mail) =>
		mail.subject.includes(orderId),
	);
}

test('a customer asks for changes on the proof, edits the design on the store and approves it, and the order is placed', async ({
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

	const permalink = await createLinkedProduct(
		admin,
		baseURL,
		`Changes tee ${theme} ${Date.now()}`,
		{ approval: 'proof_email' },
	);

	// The customer personalises the product, adds it to the cart and pays.
	await customer.goto(permalink);
	await expect(customer.locator('.pixonaut-preview *').first()).toBeVisible();
	await customer.getByRole('textbox', { name: /Slogan/ }).fill(SLOGAN);
	await customer
		.locator('form.cart, form[data-wp-interactive]')
		.getByRole('button', { name: /add to cart/i })
		.click();
	await expect(customer.getByText(/has been added to your cart/)).toBeVisible();
	const orderId = await pay(customer, checkout, checkoutUrls);
	await runJobs(request);
	const [waiting] = (await readOrder(request, orderId)).lines as [Line];
	expect(waiting.state).toBe('awaiting_customer');

	// On the emailed proof page the customer asks for changes …
	const proofMail = (await mailsFor(request, orderId)).find((mail) =>
		PROOF_PAGE.test(mail.message),
	);
	const path = PROOF_PAGE.exec(proofMail?.message ?? '')?.[0];
	expect(path, 'the email links the proof page').toBeTruthy();
	await customer.goto(new URL(path ?? '', fake.baseURL).toString());
	await customer
		.getByRole('textbox', { name: 'What should change?' })
		.fill(REQUEST);
	await customer.getByRole('button', { name: 'Request changes' }).click();

	// … and is back on the product page, the designer open on their design.
	await expect(customer).toHaveURL(ORDER_EDIT);
	const editUrl = customer.url();

	// The store hears of the request, and the customer gets a link back to
	// the design in case they close the page.
	await runJobs(request);
	const mails = await mailsFor(request, orderId);
	expect(mails.some((mail) => mail.message.includes(REQUEST))).toBe(true);
	expect(
		mails.some((mail) => mail.subject.startsWith('Edit your design')),
	).toBe(true);

	const slogan = customer.getByRole('textbox', { name: /Slogan/ });
	await expect(slogan).toHaveValue(SLOGAN);
	await slogan.fill(CORRECTED);
	await customer
		.locator('form.cart, form[data-wp-interactive]')
		.getByRole('button', { name: 'Save and approve design' })
		.click();
	await customer.waitForURL((url) => !ORDER_EDIT.test(url.search));

	// The store approved the changed design with the customer's consent, and
	// prints and orders it.
	await runJobs(request);
	await runJobs(request);
	const [ordered] = (await readOrder(request, orderId)).lines as [Line];
	expect(ordered.state).toBe('ordered');
	expect(await statuses(fake, 'PATCH /v1/designs/{id}')).toEqual([200]);
	expect(await statuses(fake, 'POST /v1/designs/{id}/approve')).toEqual([200]);
	expect(await statuses(fake, 'POST /v1/orders')).toEqual([201]);
	const [side] = Object.values(ordered.sides);
	const design = await fake.control.get(`/__fake/designs/${side?.designId}`);
	expect(await design.json()).toMatchObject({
		status: 'approved',
		revision: 2,
		consent: { kind: 'edit' },
	});

	// Editing closed once the design was approved.
	await customer.goto(editUrl);
	await expect(
		customer.getByText('This design is approved and can no longer be changed.'),
	).toBeVisible();
});
