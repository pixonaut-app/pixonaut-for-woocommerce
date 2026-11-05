import { expect, test } from '../fixtures';
import {
	buyPersonalisedProduct,
	type CheckoutUrls,
	type Line,
	openCheckouts,
	readOrder,
	runJobs,
	statuses,
} from '../orders';

/** Each checkout's address, once the tests site takes orders. */
let checkoutUrls: CheckoutUrls;

test.beforeEach(async ({ request }) => {
	checkoutUrls = await openCheckouts(request);
});

test('cancelling an order before production stops its printshop order', async ({
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
		name: `Cancelled tee ${theme} ${Date.now()}`,
	});
	await runJobs(request);
	const ordered = await readOrder(request, orderId);
	const [placed] = ordered.lines as [Line];
	expect(placed.state).toBe('ordered');

	await admin.goto(ordered.editUrl);
	// WooCommerce dresses the status select up; the select itself still takes the choice.
	await admin
		.locator('#order_status')
		.selectOption('wc-cancelled', { force: true });
	await admin.locator('button.save_order').click();
	await expect(admin.locator('#order_status')).toHaveValue('wc-cancelled');
	await runJobs(request);

	const cancelled = await readOrder(request, orderId);
	const [line] = cancelled.lines as [Line];
	expect(line).toMatchObject({ state: 'cancelled' });
	expect(line.refs).toMatchObject({
		orderId: placed.refs.orderId,
		orderStatus: 'cancelled',
	});
	expect(await statuses(fake, 'POST /v1/orders/{id}/cancel')).toEqual([200]);
	expect(cancelled.notes.join('\n')).toContain('was cancelled');
	await admin.reload();
	await expect(
		admin.locator('#pixonaut-order-lines .pixonaut-order-line'),
	).toContainText('Cancelled');
});
