import { expect, type FakePixonaut, test } from '../fixtures';
import {
	buyPersonalisedProduct,
	type CheckoutUrls,
	type Line,
	openCheckouts,
	readOrder,
	runJobs,
	statuses,
} from '../orders';

/** Tries of a step that keeps failing: the first, then one per wait of the
 *  plugin's retry schedule (1 min, 5 min, 30 min, 2 h, 12 h). */
const ATTEMPTS = 6;

/** Each checkout's address, once the tests site takes orders. */
let checkoutUrls: CheckoutUrls;

test.beforeEach(async ({ request }) => {
	checkoutUrls = await openCheckouts(request);
});

/** Arms a status fault on every request to `operation`. */
async function fail(
	fake: FakePixonaut,
	operation: string,
	status: number,
	error: string,
): Promise<void> {
	const armed = await fake.control.post('/__fake/faults', {
		data: { operation, fault: { kind: 'status', status, error } },
	});
	expect(armed.ok()).toBe(true);
}

test('an order that keeps failing is retried, then needs attention', async ({
	connectedStore: admin,
	customer,
	fake,
	request,
	baseURL,
	checkout,
	theme,
}) => {
	test.slow();
	const orderId = await buyPersonalisedProduct({
		admin,
		customer,
		fake,
		baseURL,
		checkout,
		checkoutUrls,
		name: `Retried tee ${theme} ${Date.now()}`,
	});
	await fail(fake, 'POST /v1/orders', 503, 'lock_timeout');

	await runJobs(request);
	let [line] = (await readOrder(request, orderId)).lines as [Line];
	expect(line.state).toBe('approved');
	for (let attempt = 1; attempt < ATTEMPTS; attempt++) {
		await runJobs(request, { early: true });
	}

	[line] = (await readOrder(request, orderId)).lines as [Line];
	expect(line).toMatchObject({
		state: 'needs_attention',
		reason: 'retries_exhausted',
	});
	expect(await statuses(fake, 'POST /v1/orders')).toEqual(
		Array(ATTEMPTS).fill(503),
	);
	// Nothing is left to retry.
	await runJobs(request, { early: true });
	expect(await statuses(fake, 'POST /v1/orders')).toHaveLength(ATTEMPTS);

	await admin.goto('/wp-admin/');
	await expect(
		admin.locator('.pixonaut-notice', { hasText: 'needs attention' }),
	).toBeVisible();
});

test('a printshop routing refusal needs attention at once', async ({
	connectedStore: admin,
	customer,
	fake,
	request,
	baseURL,
	checkout,
	theme,
}) => {
	test.slow();
	const orderId = await buyPersonalisedProduct({
		admin,
		customer,
		fake,
		baseURL,
		checkout,
		checkoutUrls,
		name: `Refused tee ${theme} ${Date.now()}`,
	});
	await fail(fake, 'POST /v1/orders', 422, 'printshop_disconnected');

	await runJobs(request);

	const [line] = (await readOrder(request, orderId)).lines as [Line];
	expect(line).toMatchObject({
		state: 'needs_attention',
		reason: 'printshop_disconnected',
	});
	expect(await statuses(fake, 'POST /v1/orders')).toEqual([422]);
});
