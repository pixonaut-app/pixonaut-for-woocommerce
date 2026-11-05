// A paid line whose design Pixonaut erased on its own, after the workspace's
// retention period: the webhook arrives with no request from the store, and
// every page showing the line says its personalisation was removed, with no
// proof image and nothing asked of the proof route.
import type { Page } from '@playwright/test';
import {
	expect,
	restUrl,
	TESTS_SITE_ON_NETWORK,
	test,
	WEBHOOK_ROUTE,
} from '../fixtures';
import {
	buyPersonalisedProduct,
	type CheckoutUrls,
	openCheckouts,
	readOrder,
	runJobs,
	statuses,
} from '../orders';

const REMOVED = "Removed after the shop's retention period";

/** The plugin's proof route, which every proof image the store shows is served from. */
const PROOF_ROUTE = /pixonaut(\/|%2F)v1(\/|%2F)proof/;

let checkoutUrls: CheckoutUrls;

test.beforeEach(async ({ request }) => {
	checkoutUrls = await openCheckouts(request);
});

/** The proof route's URLs $page asks for from now on. */
function proofRequests(page: Page): string[] {
	const asked: string[] = [];
	page.on('request', (r) => {
		if (PROOF_ROUTE.test(r.url())) {
			asked.push(r.url());
		}
	});
	return asked;
}

test('a line erased after the retention period reads as removed everywhere', async ({
	connectedStore: admin,
	customer,
	fake,
	request,
	baseURL,
	checkout,
	theme,
}) => {
	test.slow();
	// The fake delivers webhooks over the Docker network, where the tests
	// site is not at its browser address.
	const endpoint = await fake.control.post('/__fake/webhooks/endpoint', {
		data: { url: `${TESTS_SITE_ON_NETWORK}${restUrl(WEBHOOK_ROUTE)}` },
	});
	expect(endpoint.ok()).toBe(true);
	const orderId = await buyPersonalisedProduct({
		admin,
		customer,
		fake,
		baseURL,
		checkout,
		checkoutUrls,
		name: `Erased tee ${theme} ${Date.now()}`,
	});
	const orderReceived = customer.url();
	const placed = await readOrder(request, orderId);
	const designId = Object.values(placed.lines[0]?.sides ?? {})[0]?.designId;
	expect(designId).toEqual(expect.any(String));

	const erased = await fake.control.post('/__fake/designs/erase', {
		data: { design: designId },
	});
	expect(erased.ok()).toBe(true);
	const { attempts } = (await erased.json()) as {
		attempts: { status: number }[];
	};
	expect(attempts.map((a) => a.status)).toEqual([200]);
	// The store handles a delivered event in a job of its own.
	await runJobs(request);
	expect(
		Object.values((await readOrder(request, orderId)).lines[0]?.sides ?? {})[0]
			?.erasedAt,
	).toEqual(expect.any(Number));
	const proofsBefore = await statuses(fake, 'GET /v1/designs/{id}/proof');

	const customerAsked = proofRequests(customer);
	await customer.goto(orderReceived);
	await expect(customer.getByText(REMOVED)).toBeVisible();

	const adminAsked = proofRequests(admin);
	await admin.goto(placed.editUrl);
	const box = admin.locator('.pixonaut-order-box');
	await expect(box.locator('.pixonaut-order-line-erased')).toContainText(
		"removed after the shop's retention period",
	);
	await expect(box.locator('img')).toHaveCount(0);
	await expect(
		box.getByRole('link', { name: 'Open the design in Pixonaut' }),
	).toHaveCount(0);

	expect(customerAsked).toEqual([]);
	expect(adminAsked).toEqual([]);
	expect(await statuses(fake, 'GET /v1/designs/{id}/proof')).toEqual(
		proofsBefore,
	);
});
