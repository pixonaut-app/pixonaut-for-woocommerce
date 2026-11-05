import type { Page } from '@playwright/test';
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

/** Each checkout's address, once the tests site takes orders. */
let checkoutUrls: CheckoutUrls;

test.beforeEach(async ({ request }) => {
	checkoutUrls = await openCheckouts(request);
});

/** Arms the fake so the next order placed comes back with `outcome`. */
async function arm(
	fake: FakePixonaut,
	outcome: 'fail' | 'outcome-unknown',
): Promise<void> {
	const armed = await fake.control.post(`/__fake/orders/${outcome}`, {
		data: {},
	});
	expect(armed.ok()).toBe(true);
}

/** The order screen's section for the order's one Pixonaut line. */
function lineBox(admin: Page) {
	return admin.locator('#pixonaut-order-lines .pixonaut-order-line');
}

test('a failed printshop order is retried from the order screen', async ({
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
		name: `Failed tee ${theme} ${Date.now()}`,
	});
	await arm(fake, 'fail');
	await runJobs(request);

	const failed = await readOrder(request, orderId);
	const [stopped] = failed.lines as [Line];
	expect(stopped).toMatchObject({
		state: 'needs_attention',
		reason: 'order_failed',
	});

	await admin.goto(failed.editUrl);
	const box = lineBox(admin);
	await expect(box).toContainText('Needs attention');
	await expect(
		box.getByRole('button', { name: 'Printshop has it' }),
	).toHaveCount(0);
	await box.getByRole('button', { name: 'Retry' }).click();

	await expect(lineBox(admin)).toContainText('At the printshop');
	const [line] = (await readOrder(request, orderId)).lines as [Line];
	expect(line.state).toBe('ordered');
	expect(line.refs.orderId).not.toBe(stopped.refs.orderId);
	expect(await statuses(fake, 'POST /v1/orders/{id}/retry')).toEqual([201]);
});

test('an unknown outcome the printshop has is settled from the order screen', async ({
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
		name: `Unknown tee ${theme} ${Date.now()}`,
	});
	await arm(fake, 'outcome-unknown');
	await runJobs(request);

	const unknown = await readOrder(request, orderId);
	const [stopped] = unknown.lines as [Line];
	expect(stopped).toMatchObject({
		state: 'needs_attention',
		reason: 'outcome_unknown',
	});
	// Never sent again on its own.
	await runJobs(request, { early: true });
	expect(await statuses(fake, 'POST /v1/orders')).toEqual([201]);

	await admin.goto(unknown.editUrl);
	const box = lineBox(admin);
	await expect(box.getByRole('button', { name: 'Retry' })).toHaveCount(0);
	await box
		.getByLabel('What did the printshop say?')
		.fill('The printshop confirmed it by phone.');
	await box
		.getByLabel("The printshop's order reference, if it gave one")
		.fill(`PS-${theme}-${Date.now()}`);
	await box.getByRole('button', { name: 'Printshop has it' }).click();

	await expect(lineBox(admin)).toContainText('At the printshop');
	const [line] = (await readOrder(request, orderId)).lines as [Line];
	expect(line).toMatchObject({ state: 'ordered' });
	expect(line.refs).toMatchObject({
		orderId: stopped.refs.orderId,
		orderStatus: 'submitted',
	});
	expect(await statuses(fake, 'PATCH /v1/orders/{id}')).toEqual([200]);
});

test('an unknown outcome the printshop does not have is sent again from the order screen', async ({
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
		name: `Lost tee ${theme} ${Date.now()}`,
	});
	await arm(fake, 'outcome-unknown');
	await runJobs(request);
	const unknown = await readOrder(request, orderId);
	const [stopped] = unknown.lines as [Line];

	await admin.goto(unknown.editUrl);
	const box = lineBox(admin);
	await box
		.getByLabel('What did the printshop say?')
		.fill('The printshop has no order under that number.');
	await box
		.getByRole('button', { name: 'Printshop does not have it: send again' })
		.click();

	await expect(lineBox(admin)).toContainText('At the printshop');
	const [line] = (await readOrder(request, orderId)).lines as [Line];
	expect(line.state).toBe('ordered');
	expect(line.refs.orderId).not.toBe(stopped.refs.orderId);
	expect(await statuses(fake, 'PATCH /v1/orders/{id}')).toEqual([200]);
	expect(await statuses(fake, 'POST /v1/orders/{id}/retry')).toEqual([201]);
});

test('an address changed on the order screen is sent to the printshop order', async ({
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
		name: `Moved tee ${theme} ${Date.now()}`,
	});
	await runJobs(request);
	const ordered = await readOrder(request, orderId);
	expect((ordered.lines as [Line])[0].state).toBe('ordered');

	await admin.goto(ordered.editUrl);
	// The order ships to its shipping address, or to its billing one without it.
	for (const group of ['billing', 'shipping']) {
		await admin
			.locator('.order_data_column')
			.filter({ has: admin.locator(`#_${group}_city`) })
			.locator('a.edit_address')
			.click();
		await admin.locator(`#_${group}_city`).fill('Oakland');
	}
	await admin.locator('button.save_order').click();
	await expect(admin.locator('#message')).toBeVisible();
	await runJobs(request);
	await admin.reload();

	await expect(lineBox(admin)).toContainText(
		'New delivery address: taken by the printshop.',
	);
	expect(await statuses(fake, 'PATCH /v1/orders/{id}/delivery')).toEqual([200]);
});
