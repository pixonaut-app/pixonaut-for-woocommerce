import { beforeEach, describe, expect, it } from 'vitest';
import { createFake } from '../../fake-pixonaut/src/app';
import { FIXTURE } from '../../fake-pixonaut/src/fixture-ids';
import { findPrint } from '../../fake-pixonaut/src/handlers/prints';
import { bearer, MAIN, SITE_URL, seedPrint, send } from './support';

const fake = createFake();
const asStore = bearer(MAIN.integrationKey);

const shipping = {
	recipient: { name: 'Ada Lovelace' },
	shippingAddress: { line1: 'Storgatan 1', city: 'Lund', country: 'SE' },
};
const failedReason = 'The printshop has no order under this number.';
const settledReason = 'The printshop has it.';

type OrderRead = {
	id: string;
	status: string;
	items: {
		id: string;
		status: string;
		submissions: { printshopOrderId: string | null }[];
	}[];
	printshopOrders: { id: string; externalOrderRef: string | null }[];
	[key: string]: unknown;
};

function request(method: string, path: string, json?: unknown) {
	return send(fake, path, { method, json, headers: asStore });
}

function arm(
	outcome: 'fail' | 'outcome-unknown',
	json: Record<string, unknown> = {},
) {
	return send(fake, `/__fake/orders/${outcome}`, { method: 'POST', json });
}

async function read(id: string): Promise<OrderRead> {
	const response = await request('GET', `/v1/orders/${id}`);
	expect(response.status).toBe(200);
	return response.json();
}

/** The printshop order the order's first item went in. */
function printshopOrderId(order: OrderRead): string {
	const id = order.items[0]?.submissions.at(-1)?.printshopOrderId;
	if (id == null) {
		throw new Error(`Order ${order.id} was sent to no printshop.`);
	}
	return id;
}

function settle(order: OrderRead, json: unknown, printshopOrder?: string) {
	return request(
		'PATCH',
		`/v1/orders/${order.id}/printshop-orders/${printshopOrder ?? printshopOrderId(order)}`,
		json,
	);
}

function retry(orderId: string, itemId: string) {
	return request('POST', `/v1/orders/${orderId}/items/${itemId}/retry`);
}

/** Lets the main store's tenant move orders as a sandbox does. */
function inSandbox(): void {
	const tenant = fake.state.tenants.get(FIXTURE.tenant.live);
	if (tenant === undefined) {
		throw new Error('The fixture set has no live tenant.');
	}
	tenant.environment = 'sandbox';
}

let printId: string;
let orders = 0;

/** Orders the print, again each time, as one item, or as many items as
 *  `items` names; answers the order. */
async function placed(items = 1): Promise<OrderRead> {
	orders += 1;
	const response = await send(fake, '/v1/orders', {
		method: 'POST',
		headers: { ...asStore, 'idempotency-key': `order-${orders}` },
		json: {
			externalRef: 'wc-42',
			...shipping,
			items: Array.from({ length: items }, (_, index) => ({
				externalRef: `wc-42-${index + 1}`,
				prints: [{ printId }],
				variant: FIXTURE.variant.teeWhiteS,
				// Each test orders the one print as often as it needs.
				reorder: true,
			})),
		},
	});
	expect(response.status).toBe(201);
	return response.json();
}

beforeEach(async () => {
	fake.reset('default', SITE_URL);
	const design = await send(fake, '/__fake/designs', {
		method: 'POST',
		json: {
			id: 'dsn_1',
			status: 'approved',
			blankId: FIXTURE.blank.tee,
			placementId: FIXTURE.placement.front,
		},
	});
	expect(design.status).toBe(200);
	printId = seedPrint(fake, 'dsn_1');
});

describe('an outcome armed unknown', () => {
	it('places the armed number of items outcome_unknown, then submits again', async () => {
		const armed = await arm('outcome-unknown', { times: 2 });
		expect(armed.status).toBe(200);
		expect(await armed.json()).toEqual({
			outcome: 'outcome_unknown',
			remaining: 2,
		});

		const first = await placed();
		const second = await placed();
		const third = await placed();

		for (const unknown of [first, second]) {
			expect(unknown).toMatchObject({
				status: 'needs_attention',
				items: [
					{
						status: 'outcome_unknown',
						lastError: null,
						canRetry: false,
						retryable: false,
						statusMoves: ['submitted', 'failed'],
					},
				],
			});
		}
		expect(third.items[0]?.status).toBe('submitted');
		expect(fake.violations).toEqual([]);
	});

	it('refuses a count that is no count', async () => {
		const response = await arm('outcome-unknown', { times: 0 });

		expect(response.status).toBe(400);
		expect(fake.state.armedOrderOutcome).toBeNull();
	});

	it('replaces an armed failure', async () => {
		await arm('fail');
		await arm('outcome-unknown');

		expect((await placed()).items[0]?.status).toBe('outcome_unknown');
		expect((await placed()).items[0]?.status).toBe('submitted');
	});
});

describe('PATCH /v1/orders/{id}/printshop-orders/{printshopOrderId}', () => {
	it("settles an unknown outcome as submitted with the printshop's reference", async () => {
		await arm('outcome-unknown');
		const order = await placed();

		const response = await settle(order, {
			status: 'submitted',
			reason: settledReason,
			externalOrderRef: 'PS-1001',
		});

		expect(response.status).toBe(200);
		const body = await response.json();
		expect(body).toMatchObject({
			id: order.id,
			status: 'open',
			items: [{ status: 'submitted', lastError: null, statusMoves: [] }],
			printshopOrders: [{ externalOrderRef: 'PS-1001' }],
		});
		expect(body.history.at(-1)).toMatchObject({
			type: 'item.status_changed',
			status: 'submitted',
			itemId: order.items[0]?.id,
			reason: settledReason,
			via: 'store_key',
		});
		expect(fake.violations).toEqual([]);
	});

	it('moves every item sent in the printshop order', async () => {
		await arm('outcome-unknown', { times: 2 });
		const order = await placed(2);

		await settle(order, { status: 'failed', reason: failedReason });

		const after = await read(order.id);
		expect(after.items.map((item) => item.status)).toEqual([
			'failed',
			'failed',
		]);
		expect(fake.violations).toEqual([]);
	});

	it('settles an unknown outcome as failed, which can then be retried', async () => {
		await arm('outcome-unknown');
		const order = await placed();

		const response = await settle(order, {
			status: 'failed',
			reason: failedReason,
		});

		expect(response.status).toBe(200);
		const after = await read(order.id);
		expect(after).toMatchObject({
			status: 'needs_attention',
			items: [
				{
					status: 'failed',
					lastError: failedReason,
					canRetry: true,
					retryable: true,
					statusMoves: [],
				},
			],
		});
		expect((after.history as unknown[]).at(-1)).toMatchObject({
			type: 'item.failed',
			status: 'failed',
			via: 'store_key',
		});
		expect(fake.violations).toEqual([]);
	});

	it('refuses to settle an unknown outcome without a reason', async () => {
		await arm('outcome-unknown');
		const order = await placed();

		const response = await settle(order, { status: 'submitted' });

		expect(response.status).toBe(422);
		expect(await response.json()).toEqual({
			error: 'order_status_reason_required',
			from: 'outcome_unknown',
		});
		expect((await read(order.id)).items[0]?.status).toBe('outcome_unknown');
		expect(fake.violations).toEqual([]);
	});

	it('refuses a reference another order of the tenant holds', async () => {
		await arm('outcome-unknown', { times: 2 });
		const first = await placed();
		const second = await placed();
		const settled = {
			status: 'submitted',
			reason: settledReason,
			externalOrderRef: 'PS-1001',
		};
		expect((await settle(first, settled)).status).toBe(200);

		const response = await settle(second, settled);

		expect(response.status).toBe(409);
		expect(await response.json()).toEqual({ error: 'order_reference_taken' });
		expect((await read(second.id)).printshopOrders[0]?.externalOrderRef).toBe(
			null,
		);
		expect(fake.violations).toEqual([]);
	});

	it('refuses any move on live once the outcome is known, and a reference without submitted', async () => {
		const order = await placed();

		const shipped = await settle(order, { status: 'shipped' });
		const reference = await settle(order, {
			status: 'failed',
			externalOrderRef: 'PS-1001',
		});

		expect(shipped.status).toBe(409);
		expect(await shipped.json()).toEqual({
			error: 'order_status_not_allowed',
			from: 'submitted',
			to: 'shipped',
		});
		expect(reference.status).toBe(400);
		expect(fake.violations).toEqual([]);
	});

	it('answers an unknown order or printshop order not found', async () => {
		const order = await placed();

		const noOrder = await request(
			'PATCH',
			'/v1/orders/ord_unknown/printshop-orders/pso_1',
			{ status: 'failed' },
		);
		const noPrintshopOrder = await settle(order, { status: 'failed' }, 'pso_1');

		expect(noOrder.status).toBe(404);
		expect(await noOrder.json()).toEqual({ error: 'order_not_found' });
		expect(noPrintshopOrder.status).toBe(404);
		expect(await noPrintshopOrder.json()).toEqual({ error: 'not_found' });
		expect(fake.violations).toEqual([]);
	});

	describe('in a sandbox', () => {
		beforeEach(inSandbox);

		it('moves an item forward to shipped', async () => {
			const order = await placed();
			expect(order.items[0]).toMatchObject({
				statusMoves: ['queued', 'printed', 'shipped', 'failed'],
			});

			const queued = await settle(order, { status: 'queued' });
			const shipped = await settle(order, { status: 'shipped' });

			expect(queued.status).toBe(200);
			expect(shipped.status).toBe(200);
			expect(await read(order.id)).toMatchObject({
				status: 'shipped',
				items: [{ status: 'shipped', statusMoves: [] }],
			});
			expect(fake.violations).toEqual([]);
		});

		it('refuses a move back, to cancelled, or out of a final status', async () => {
			const order = await placed();
			await settle(order, { status: 'printed' });

			const back = await settle(order, { status: 'queued' });
			const cancelled = await settle(order, { status: 'cancelled' });
			await settle(order, { status: 'failed' });
			const out = await settle(order, { status: 'shipped' });

			expect(await back.json()).toEqual({
				error: 'order_status_not_allowed',
				from: 'printed',
				to: 'queued',
			});
			expect(cancelled.status).toBe(409);
			expect(await out.json()).toEqual({
				error: 'order_status_not_allowed',
				from: 'failed',
				to: 'shipped',
			});
			expect(fake.violations).toEqual([]);
		});
	});
});

describe('POST /v1/orders/{id}/items/{itemId}/retry', () => {
	it('submits a failed item again in a new printshop order, keeping its id', async () => {
		await arm('fail');
		const failed = await placed();
		const [item] = failed.items;
		if (item === undefined) {
			throw new Error('No item.');
		}

		const response = await retry(failed.id, item.id);

		expect(response.status).toBe(201);
		const body = await response.json();
		expect(body).toMatchObject({
			id: failed.id,
			status: 'open',
			items: [
				{
					id: item.id,
					status: 'submitted',
					lastError: null,
					canRetry: false,
					printIds: [printId],
				},
			],
		});
		expect(body.items[0].submissions).toHaveLength(2);
		expect(body.printshopOrders).toHaveLength(2);
		expect(body.history.at(-1)).toMatchObject({
			type: 'item.submitted',
			itemId: item.id,
		});
		expect(fake.violations).toEqual([]);
	});

	it('fails the retry too when a failure is armed', async () => {
		await arm('fail', { times: 2 });
		const failed = await placed();
		const itemId = failed.items[0]?.id ?? '';

		const body = await (await retry(failed.id, itemId)).json();

		expect(body.items[0]).toMatchObject({ status: 'failed', canRetry: true });
		expect(fake.violations).toEqual([]);
	});

	it('draws a failed print again in place, and the item waits for it', async () => {
		await arm('fail');
		const failed = await placed();
		const drawn = findPrint(fake.state, printId, MAIN.id);
		if (drawn === undefined) {
			throw new Error('No print.');
		}
		drawn.status = 'failed';

		const response = await retry(failed.id, failed.items[0]?.id ?? '');

		expect(response.status).toBe(201);
		expect((await response.json()).items[0]).toMatchObject({
			status: 'awaiting_prints',
			submissions: [{}, { printshopOrderId: null }],
		});
		expect(drawn.status).toBe('created');
		expect(fake.violations).toEqual([]);
	});

	it('refuses an item that has not failed', async () => {
		const order = await placed();
		const itemId = order.items[0]?.id ?? '';

		const notFailed = await retry(order.id, itemId);

		expect(notFailed.status).toBe(409);
		expect(await notFailed.json()).toEqual({
			error: 'retry_refused',
			status: 'submitted',
			atPrintshop: false,
		});
		expect(fake.violations).toEqual([]);
	});

	it('refuses a retry once the printshop the item went to is disconnected', async () => {
		await arm('fail');
		const failed = await placed();
		const main = fake.state.printshops.get(FIXTURE.printshop.main);
		if (main === undefined) {
			throw new Error('No main printshop.');
		}
		main.active = false;

		const response = await retry(failed.id, failed.items[0]?.id ?? '');

		expect(response.status).toBe(422);
		expect((await response.json()).error).toBe('printshop_disconnected');
		expect((await read(failed.id)).items[0]).toMatchObject({ canRetry: true });
		expect(fake.violations).toEqual([]);
	});

	it('refuses a retry of a print of a revision the design has moved past, and offers none', async () => {
		await arm('fail');
		const failed = await placed();
		await send(fake, '/__fake/designs', {
			method: 'POST',
			json: {
				id: 'dsn_1',
				status: 'approved',
				blankId: FIXTURE.blank.tee,
				placementId: FIXTURE.placement.front,
				revision: 2,
			},
		});

		expect((await read(failed.id)).items[0]).toMatchObject({
			canRetry: false,
			retryable: false,
		});
		const response = await retry(failed.id, failed.items[0]?.id ?? '');

		expect(response.status).toBe(409);
		expect(await response.json()).toEqual({ error: 'print_superseded' });
		expect(fake.violations).toEqual([]);
	});

	it('answers an unknown order or item not found', async () => {
		const order = await placed();

		const noOrder = await retry('ord_unknown', 'itm_1');
		const noItem = await retry(order.id, 'itm_1');

		expect(await noOrder.json()).toEqual({ error: 'order_not_found' });
		expect(noItem.status).toBe(404);
		expect(await noItem.json()).toEqual({ error: 'item_not_found' });
		expect(fake.violations).toEqual([]);
	});
});

describe('cancelling', () => {
	function cancel(id: string) {
		return request('POST', `/v1/orders/${id}/cancel`);
	}

	function cancelItem(id: string, itemId: string) {
		return request('POST', `/v1/orders/${id}/items/${itemId}/cancel`);
	}

	it('cancels a submitted order, naming its items, then answers it unchanged', async () => {
		const order = await placed();

		const first = await cancel(order.id);
		const again = await cancel(order.id);

		expect(first.status).toBe(200);
		expect(await first.json()).toMatchObject({
			id: order.id,
			status: 'cancelled',
			cancelledItemIds: [order.items[0]?.id],
		});
		expect(again.status).toBe(200);
		expect((await again.json()).cancelledItemIds).toEqual([]);
		expect(((await read(order.id)).history as unknown[]).at(-1)).toMatchObject({
			type: 'order.cancelled',
			status: 'cancelled',
			via: 'store_key',
		});
		expect(fake.violations).toEqual([]);
	});

	it('cancels an order whose outcome is unknown', async () => {
		await arm('outcome-unknown');
		const order = await placed();

		const response = await cancel(order.id);

		expect(response.status).toBe(200);
		expect((await response.json()).status).toBe('cancelled');
		expect(fake.violations).toEqual([]);
	});

	it('refuses a failed order', async () => {
		await arm('fail');
		const order = await placed();

		const response = await cancel(order.id);

		expect(response.status).toBe(409);
		expect(await response.json()).toEqual({
			error: 'order_status_not_allowed',
			from: 'failed',
			to: 'cancelled',
		});
		expect(fake.violations).toEqual([]);
	});

	it('refuses an order the printshop has made', async () => {
		inSandbox();
		const order = await placed();
		await settle(order, { status: 'printed' });

		const response = await cancel(order.id);

		expect(response.status).toBe(409);
		expect(await response.json()).toEqual({
			error: 'order_at_printshop',
			cause: 'made',
		});
		expect((await read(order.id)).items[0]?.status).toBe('printed');
		expect(fake.violations).toEqual([]);
	});

	it('cancels an item with every item sent in its printshop order', async () => {
		const order = await placed(2);
		const [first, second] = order.items;

		const response = await cancelItem(order.id, first?.id ?? '');

		expect(response.status).toBe(200);
		expect(await response.json()).toMatchObject({
			status: 'cancelled',
			cancelledItemIds: [first?.id, second?.id],
		});
		expect(fake.violations).toEqual([]);
	});

	it('cancels an item no printshop has yet alone', async () => {
		const waiting = seedPrint(fake, 'dsn_1', { status: 'created' });
		const response = await send(fake, '/v1/orders', {
			method: 'POST',
			headers: { ...asStore, 'idempotency-key': 'waiting' },
			json: {
				items: [printId, waiting].map((id) => ({
					prints: [{ printId: id }],
					variant: FIXTURE.variant.teeWhiteS,
					reorder: true,
				})),
			},
		});
		const order: OrderRead = await response.json();
		const [sent, unsent] = order.items;

		const cancelled = await cancelItem(order.id, unsent?.id ?? '');

		expect(await cancelled.json()).toMatchObject({
			status: 'open',
			cancelledItemIds: [unsent?.id],
			items: [{ id: sent?.id, status: 'submitted' }, { status: 'cancelled' }],
		});
		expect(fake.violations).toEqual([]);
	});

	it('answers an unknown order or item not found', async () => {
		const order = await placed();

		const noOrder = await cancel('ord_unknown');
		const noItem = await cancelItem(order.id, 'itm_1');

		expect(await noOrder.json()).toEqual({ error: 'order_not_found' });
		expect(await noItem.json()).toEqual({ error: 'item_not_found' });
		expect(fake.violations).toEqual([]);
	});
});

describe('PATCH /v1/orders/{id}/delivery', () => {
	const moved = {
		recipient: { name: 'Ada Lovelace', email: 'ada@example.org' },
		shippingAddress: {
			line1: 'Kungsgatan 9',
			city: 'Göteborg',
			postalCode: '411 19',
			country: 'SE',
		},
	};

	function changeDelivery(id: string, key: string) {
		return send(fake, `/v1/orders/${id}/delivery`, {
			method: 'PATCH',
			json: moved,
			headers: { ...asStore, 'idempotency-key': key },
		});
	}

	it('gives a submitted order the new address, accepted, once for a key', async () => {
		const { id } = await placed();

		const first = await changeDelivery(id, 'delivery-1');
		const replay = await changeDelivery(id, 'delivery-1');

		expect(first.status).toBe(200);
		expect(await first.json()).toMatchObject({
			id,
			...moved,
			delivery: { state: 'accepted', words: null },
		});
		expect(replay.headers.get('Idempotent-Replayed')).toBe('true');
		const order = await read(id);
		expect(order.deliveryChanges).toMatchObject([
			{
				added: false,
				by: 'store_key',
				outcome: 'accepted',
				words: null,
				printshopOrderId: null,
			},
		]);
		expect(order.deliveryChangeRefusal).toBeNull();
		expect((order.history as unknown[]).at(-1)).toMatchObject({
			type: 'order.delivery_changed',
			via: 'store_key',
		});
		expect(fake.violations).toEqual([]);
	});

	it('carries the new address with an order still waiting for its prints', async () => {
		const waiting = seedPrint(fake, 'dsn_1', { status: 'created' });
		const order = await send(fake, '/v1/orders', {
			method: 'POST',
			headers: { ...asStore, 'idempotency-key': 'order-waiting' },
			json: {
				items: [
					{
						prints: [{ printId: waiting }],
						variant: FIXTURE.variant.teeWhiteS,
					},
				],
			},
		});
		const { id } = await order.json();

		const response = await changeDelivery(id, 'delivery-1');

		expect(response.status).toBe(200);
		expect(await response.json()).toMatchObject({
			status: 'open',
			items: [{ status: 'awaiting_prints' }],
			...moved,
			delivery: { state: 'carried', words: null },
		});
		expect((await read(id)).deliveryChangeRefusal).toBeNull();
		expect(fake.violations).toEqual([]);
	});

	it('refuses an order its printshop is making, naming its status', async () => {
		inSandbox();
		const order = await placed();
		await settle(order, { status: 'queued' });

		const response = await changeDelivery(order.id, 'delivery-1');

		expect(response.status).toBe(409);
		expect(await response.json()).toEqual({
			error: 'order_past_address_change',
			status: 'queued',
			words: null,
		});
		expect((await read(order.id)).deliveryChangeRefusal).toEqual({
			code: 'order_past_address_change',
			status: 'queued',
			unanswered: false,
		});
		expect(fake.violations).toEqual([]);
	});

	it('refuses a printshop that takes no address changes, naming it', async () => {
		const printshop = fake.state.printshops.get(FIXTURE.printshop.main);
		if (printshop === undefined) {
			throw new Error('The fixture set has no main printshop.');
		}
		printshop.acceptsAddressUpdates = false;
		const { id } = await placed();

		const response = await changeDelivery(id, 'delivery-1');

		expect(response.status).toBe(409);
		expect(await response.json()).toEqual({
			error: 'address_change_not_accepted',
			printshop: printshop.name,
		});
		expect((await read(id)).shippingAddress).toEqual(shipping.shippingAddress);
		expect(fake.violations).toEqual([]);
	});

	it('answers an unknown order order_not_found', async () => {
		const response = await changeDelivery('ord_unknown', 'delivery-1');

		expect(response.status).toBe(404);
		expect(fake.violations).toEqual([]);
	});
});
