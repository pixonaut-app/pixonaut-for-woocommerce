import type { APIRequestContext } from '@playwright/test';
import {
	expect,
	type FakePixonaut,
	restUrl,
	test,
	usePlainPermalinks,
} from '../fixtures';
import { type Line, openCheckouts, pay, readOrder, runJobs } from '../orders';
import { publishLinkedProduct } from '../products';

/** The tests site as the fake reaches it: by its service name on wp-env's
 *  Docker network. */
const TESTS_SITE_ON_NETWORK = 'http://tests-wordpress';

/** The plugin's webhook receiver. */
const WEBHOOK_ROUTE = '/pixonaut/v1/webhook';

/** What the customer writes on the fixture tee's text layer. */
const SLOGAN = 'Approved by email';

/** The proof-by-email approval mode's name in the product panel. */
const PROOF_EMAIL_MODE = 'Proof by email after payment';

/** A proof page's path on the fake, which stands in for Pixonaut's. */
const PROOF_PAGE = /\/h\/proof\/[A-Za-z0-9_-]+/;

type Mail = { to: string[]; subject: string; message: string };

/** The emails the tests site sent whose subject names the order. */
async function mailsFor(
	request: APIRequestContext,
	orderId: string,
): Promise<Mail[]> {
	const response = await request.get(restUrl('/pixonaut-e2e/v1/mails'));
	expect(response.ok()).toBe(true);
	return ((await response.json()) as Mail[]).filter((mail) =>
		mail.subject.includes(`order ${orderId}`),
	);
}

/** The statuses the fake answered an operation with, in order. */
async function statuses(
	fake: FakePixonaut,
	operation: string,
): Promise<number[]> {
	return (await fake.requests())
		.filter((r) => r.operation === operation)
		.map((r) => r.status);
}

test('a customer approves the emailed proof after payment and only then is the order placed', async ({
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

	// The merchant links a product approved by proof email.
	await usePlainPermalinks(admin, baseURL);
	await publishLinkedProduct(admin, `Proof tee ${theme} ${Date.now()}`, '25', {
		approval: PROOF_EMAIL_MODE,
	});
	const permalink = await admin
		.locator('#wp-admin-bar-view a, #view-post-btn a')
		.first()
		.getAttribute('href');
	expect(permalink).toBeTruthy();

	// The customer personalises it, adds it to the cart and pays.
	await customer.goto(permalink ?? '');
	await expect(customer.locator('.pixonaut-preview *').first()).toBeVisible();
	await customer.getByRole('textbox', { name: /Slogan/ }).fill(SLOGAN);
	await customer
		.locator('form.cart, form[data-wp-interactive]')
		.getByRole('button', { name: /add to cart/i })
		.click();
	await expect(customer.getByText(/has been added to your cart/)).toBeVisible();
	const orderId = await pay(customer, checkout, checkoutUrls);
	await runJobs(request);

	// The line waits for the customer; nothing is approved or ordered.
	const [waiting] = (await readOrder(request, orderId)).lines as [Line];
	expect(waiting.state).toBe('awaiting_customer');
	expect(waiting.refs.consent ?? null).toBeNull();
	expect(await statuses(fake, 'POST /v1/share-links')).toEqual([201]);
	expect(await statuses(fake, 'POST /v1/designs/{id}/approve')).toEqual([]);
	expect(await statuses(fake, 'POST /v1/orders')).toEqual([]);

	// The customer gets the proof by email …
	const [mail] = await mailsFor(request, orderId);
	expect(mail?.to).toContain('ada@example.com');
	const path = PROOF_PAGE.exec(mail?.message ?? '')?.[0];
	expect(path, 'the email links the proof page').toBeTruthy();

	// … and approves it on the proof page. The link names the fake by the
	// host the tests site reached it on; the browser reaches it at its base.
	await customer.goto(new URL(path ?? '', fake.baseURL).toString());
	await customer.getByRole('button', { name: 'Approve' }).click();
	await expect(customer.getByText('Design approved')).toBeVisible();

	// Pixonaut tells the store, which orders the line.
	await runJobs(request);
	await runJobs(request);
	const [ordered] = (await readOrder(request, orderId)).lines as [Line];
	expect(ordered.state).toBe('ordered');
	expect(await statuses(fake, 'POST /v1/designs/{id}/approve')).toEqual([]);
	expect(await statuses(fake, 'POST /v1/orders')).toEqual([201]);
	const [side] = Object.values(ordered.sides);
	const design = await fake.control.get(`/__fake/designs/${side?.designId}`);
	expect(await design.json()).toMatchObject({
		status: 'approved',
		consent: { kind: 'proof_page' },
	});
});
