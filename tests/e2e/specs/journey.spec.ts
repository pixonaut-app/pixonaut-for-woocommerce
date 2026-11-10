import type { APIRequestContext, Page } from '@playwright/test';
import {
	expect,
	type FakePixonaut,
	restUrl,
	TESTS_SITE_ON_NETWORK,
	test,
	WEBHOOK_ROUTE,
} from '../fixtures';
import {
	buyPersonalisedProduct,
	type CheckoutUrls,
	type Line,
	openCheckouts,
	pendingFulfilment,
	readOrder,
	runJobs,
	statuses,
} from '../orders';

/** The recurring pull of the event stream's Action Scheduler hook. */
const PULL_HOOK = 'pixonaut_wc_events_pull';

/** Runs the plugin's pending jobs of one hook now, due or not. */
async function runHook(
	request: APIRequestContext,
	hook: string,
): Promise<void> {
	const response = await request.post(restUrl('/pixonaut-e2e/v1/jobs'), {
		data: { hook },
	});
	expect(response.ok(), `the tests site runs ${hook}`).toBe(true);
}

/** Reports the order's payment again, as a gateway that reports it twice. */
async function reportPaymentAgain(
	request: APIRequestContext,
	orderId: string,
): Promise<void> {
	const response = await request.post(
		restUrl(`/pixonaut-e2e/v1/orders/${orderId}/payment-complete`),
	);
	expect(response.status()).toBe(204);
}

/** Each checkout's address, once the tests site takes orders. */
let checkoutUrls: CheckoutUrls;

test.beforeEach(async ({ request }) => {
	checkoutUrls = await openCheckouts(request);
});

type Shop = {
	admin: Page;
	customer: Page;
	fake: FakePixonaut;
	request: APIRequestContext;
	baseURL: string | undefined;
	checkout: 'classic' | 'block';
	theme: string;
};

type Ordered = {
	orderId: string;
	line: Line;
	side: Record<string, unknown> | undefined;
};

/** A customer personalises a linked tee, pays for it, and the line's job
 *  places its printshop order. */
async function orderPersonalisedTee({
	admin,
	customer,
	fake,
	request,
	baseURL,
	checkout,
	theme,
}: Shop): Promise<Ordered> {
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
		name: `Journey tee ${theme} ${Date.now()}`,
		linkedInAdmin: true,
	});
	const placed = await readOrder(request, orderId);
	expect(placed.status).toBe('processing');
	expect(placed.lines).toHaveLength(1);
	const itemId = placed.lines[0]?.itemId;
	// The checkout itself started the line's job.
	expect(await pendingFulfilment(request)).toEqual([itemId]);

	// A payment reported twice starts one job.
	await reportPaymentAgain(request, orderId);
	expect(await pendingFulfilment(request)).toEqual([itemId]);
	await runJobs(request);
	const ordered = await readOrder(request, orderId);
	const [line] = ordered.lines as [Line];
	// One side: the product is linked to the tee's front only.
	const [side] = Object.values(line.sides);
	expect(line.state).toBe('ordered');
	expect(await statuses(fake, 'POST /v1/designs/{id}/approve')).toEqual([200]);
	expect(await statuses(fake, 'POST /v1/orders')).toEqual([201]);
	return { orderId, line, side };
}

/** Moves the line's printshop order on to `shipped`; resolves with the
 *  status each delivery got, none for a delivery the fake dropped. */
async function shipAtPrintshop(
	fake: FakePixonaut,
	{ line }: Ordered,
): Promise<number[]> {
	const moved = await fake.control.post('/__fake/orders/move', {
		data: {
			orderId: line.refs.orderId,
			externalRef: line.refs.externalRef,
		},
	});
	expect(moved.ok()).toBe(true);
	const { attempts } = (await moved.json()) as {
		attempts: { status: number }[];
	};
	return attempts.map((a) => a.status);
}

async function expectShipped(
	request: APIRequestContext,
	orderId: string,
): Promise<void> {
	const shipped = await readOrder(request, orderId);
	expect(shipped.status).toBe('completed');
	expect(shipped.lines[0]).toMatchObject({
		state: 'shipped',
		refs: { tracking: { number: 'PN000000001SE' } },
	});
}

test('a personalised product goes from the product page to a shipped printshop order', async ({
	connectedStore: admin,
	customer,
	fake,
	request,
	baseURL,
	checkout,
	theme,
}) => {
	test.slow();
	const ordered = await orderPersonalisedTee({
		admin,
		customer,
		fake,
		request,
		baseURL,
		checkout,
		theme,
	});

	// … and a payment reported after the printshop order places no other.
	await reportPaymentAgain(request, ordered.orderId);
	await runJobs(request);
	expect(await statuses(fake, 'POST /v1/orders')).toEqual([201]);

	// The printshop ships it.
	expect(await shipAtPrintshop(fake, ordered)).toEqual([200, 200, 200]);
	await runJobs(request);

	await expectShipped(request, ordered.orderId);
});

test('with every webhook lost, one pull of the event stream ships the line', async ({
	connectedStore: admin,
	customer,
	fake,
	request,
	baseURL,
	checkout,
	theme,
}) => {
	test.slow();
	const ordered = await orderPersonalisedTee({
		admin,
		customer,
		fake,
		request,
		baseURL,
		checkout,
		theme,
	});
	const drop = await fake.control.post('/__fake/webhooks/drop', {
		data: { drop: true },
	});
	expect(drop.ok()).toBe(true);

	expect(await shipAtPrintshop(fake, ordered), 'nothing delivered').toEqual([]);
	await runJobs(request);
	expect((await readOrder(request, ordered.orderId)).lines[0]?.state).toBe(
		'ordered',
	);

	await runHook(request, PULL_HOOK);
	await runJobs(request);

	await expectShipped(request, ordered.orderId);
	expect(await statuses(fake, 'GET /v1/events')).not.toContain(400);
	expect(
		await statuses(fake, 'POST /v1/webhooks/{id}/deliveries/redeliver-failed'),
		'the deliveries that failed are asked for again',
	).toEqual([202]);
});
