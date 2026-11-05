import type { Page } from '@playwright/test';
import { expect, type FakePixonaut, restUrl, test } from '../fixtures';
import {
	buyPersonalisedProduct,
	type CheckoutUrls,
	type Line,
	openCheckouts,
	readOrder,
	runJobs,
	statuses,
} from '../orders';

/** The plugin's abilities, as an MCP client runs them through the
 *  Abilities API. */
const ABILITIES = '/wp-abilities/v1/abilities/pixonaut-for-woocommerce';

/** Each checkout's address, once the tests site takes orders. */
let checkoutUrls: CheckoutUrls;

test.beforeEach(async ({ request }) => {
	checkoutUrls = await openCheckouts(request);
});

/** Arms the fake so the next order placed comes back failed. */
async function failNextOrder(fake: FakePixonaut): Promise<void> {
	const armed = await fake.control.post('/__fake/orders/fail', { data: {} });
	expect(armed.ok()).toBe(true);
}

/** A REST nonce for the signed-in admin, as a client signed in as them
 *  sends with each run. */
async function restNonce(admin: Page): Promise<string> {
	const response = await admin.request.get(
		'/wp-admin/admin-ajax.php?action=rest-nonce',
	);
	expect(response.ok()).toBe(true);
	return (await response.text()).trim();
}

test('a failed printshop order is retried over the abilities an MCP client runs', async ({
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
		name: `MCP retried tee ${theme} ${Date.now()}`,
	});
	await failNextOrder(fake);
	await runJobs(request);
	const [stopped] = (await readOrder(request, orderId)).lines as [Line];
	expect(stopped).toMatchObject({
		state: 'needs_attention',
		reason: 'order_failed',
	});
	const headers = { 'X-WP-Nonce': await restNonce(admin) };

	// A read-only ability runs as GET, its input as query parameters.
	const listed = await admin.request.get(
		restUrl(`${ABILITIES}/list-personalised-orders/run`),
		{ headers, params: { 'input[order_id]': orderId } },
	);
	expect(listed.ok(), await listed.text()).toBe(true);
	const list = await listed.json();
	expect(list.total).toBe(1);
	expect(list.orders[0].lines[0]).toMatchObject({
		item_id: stopped.itemId,
		state: 'needs_attention',
		actions: ['retry'],
	});

	const acted = await admin.request.post(
		restUrl(`${ABILITIES}/act-on-order-line/run`),
		{
			headers,
			data: {
				input: {
					order_id: Number(orderId),
					item_id: stopped.itemId,
					action: 'retry',
				},
			},
		},
	);
	expect(acted.ok(), await acted.text()).toBe(true);
	const answer = await acted.json();
	expect(answer.result).toBe('done');
	expect(answer.line.state).toBe('ordered');

	const order = await readOrder(request, orderId);
	const [line] = order.lines as [Line];
	expect(line.state).toBe('ordered');
	expect(line.refs.orderId).not.toBe(stopped.refs.orderId);
	expect(order.notes.join('\n')).toContain('sent to the printshop again');
	expect(await statuses(fake, 'POST /v1/orders/{id}/retry')).toEqual([201]);
});
