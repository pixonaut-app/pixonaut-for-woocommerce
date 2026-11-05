import { createServer, type Server } from 'node:http';
import type { AddressInfo } from 'node:net';
import {
	afterAll,
	afterEach,
	beforeAll,
	beforeEach,
	describe,
	expect,
	it,
} from 'vitest';
import { createFake } from '../../fake-pixonaut/src/app';
import { FIXTURE } from '../../fake-pixonaut/src/fixture-ids';
import {
	DEFAULT_HOLD_LIMIT,
	DEFAULT_PERIOD_END,
	MONTHLY_ORDER_LIMIT,
} from '../../fake-pixonaut/src/webhooks/hold-order';
import { bearer, MAIN, SITE_URL, seedPrint, send } from './support';

/** Bodies the store's endpoint received, parsed. */
const received: { type: string; payload: Record<string, unknown> }[] = [];
let server: Server;
let endpointUrl: string;

beforeAll(async () => {
	server = createServer((request, response) => {
		const chunks: Buffer[] = [];
		request.on('data', (chunk: Buffer) => chunks.push(chunk));
		request.on('end', () => {
			received.push(JSON.parse(Buffer.concat(chunks).toString('utf8')));
			response.end();
		});
	});
	await new Promise<void>((resolve) => server.listen(0, '127.0.0.1', resolve));
	const { port } = server.address() as AddressInfo;
	endpointUrl = `http://127.0.0.1:${port}/wp-json/pixonaut/v1/webhook`;
});

afterAll(() => new Promise<void>((resolve) => server.close(() => resolve())));

const fake = createFake();
const asStore = bearer(MAIN.integrationKey);

function control(path: string, json: unknown): Promise<Response> {
	return send(fake, `/__fake/orders/${path}`, { method: 'POST', json });
}

function read(id: string) {
	return send(fake, `/v1/orders/${id}`, { headers: asStore });
}

/** Places an order of a seeded print as the main store. */
async function placeOrder(key: string): Promise<Record<string, unknown>> {
	const design = await send(fake, '/__fake/designs', {
		method: 'POST',
		json: {
			id: `dsn_${key}`,
			status: 'approved',
			blankId: FIXTURE.blank.tee,
			placementId: FIXTURE.placement.front,
		},
	});
	expect(design.status).toBe(200);
	const response = await send(fake, '/v1/orders', {
		method: 'POST',
		headers: { ...asStore, 'idempotency-key': key },
		json: {
			prints: [{ printId: seedPrint(fake, `dsn_${key}`) }],
			variant: FIXTURE.variant.teeWhiteS,
			externalRef: `wc_${key}`,
		},
	});
	expect(response.status).toBe(201);
	return response.json();
}

beforeEach(async () => {
	fake.reset('default', SITE_URL);
	received.length = 0;
	const endpoint = await send(fake, '/__fake/webhooks/endpoint', {
		method: 'POST',
		json: { url: endpointUrl },
	});
	expect(endpoint.status).toBe(200);
});

afterEach(() => {
	expect(fake.violations).toEqual([]);
});

describe('held orders', () => {
	it('places the armed number of orders as held, with the hold, then submits again', async () => {
		expect((await control('hold', { times: 1 })).status).toBe(200);

		const held = await placeOrder('o1');
		const next = await placeOrder('o2');

		expect(held).toMatchObject({
			status: 'held',
			hold: {
				reason: MONTHLY_ORDER_LIMIT,
				limit: DEFAULT_HOLD_LIMIT,
				periodEnd: DEFAULT_PERIOD_END,
			},
		});
		expect(next).toMatchObject({ status: 'submitted', hold: null });
		const history = (await (await read(held.id as string)).json()).history;
		expect(history).toMatchObject([{ type: 'order.held', status: 'held' }]);
	});

	it('holds with the reason, limit and month end given', async () => {
		await control('hold', {
			reason: 'something_new',
			limit: 3,
			periodEnd: '2027-01-01T00:00:00Z',
		});

		const held = await placeOrder('o1');

		expect(held.hold).toEqual({
			reason: 'something_new',
			limit: 3,
			periodEnd: '2027-01-01T00:00:00Z',
		});
	});

	it('sends order.held for a held order', async () => {
		await control('hold', {});
		const held = await placeOrder('o1');

		const response = await control('held-event', { orderId: held.id });

		expect(response.status, await response.clone().text()).toBe(200);
		expect(received).toMatchObject([
			{
				type: 'order.held',
				payload: {
					orderId: held.id,
					printIds: held.printIds,
					externalRef: 'wc_o1',
					reason: MONTHLY_ORDER_LIMIT,
					limit: DEFAULT_HOLD_LIMIT,
					periodEnd: DEFAULT_PERIOD_END,
				},
			},
		]);
	});

	it.each(['upgrade', 'month_reset'])(
		'releases a held order by %s to awaiting_prints, sending order.released alone',
		async (releasedBy) => {
			const status = 'awaiting_prints';
			await control('hold', {});
			const held = await placeOrder('o1');

			const response = await control('release', {
				orderId: held.id,
				releasedBy,
			});

			expect(response.status, await response.clone().text()).toBe(200);
			expect(received).toMatchObject([
				{
					type: 'order.released',
					payload: { orderId: held.id, releasedBy, status },
				},
			]);
			const order = await (await read(held.id as string)).json();
			expect(order).toMatchObject({ status, hold: null });
			expect(order.history.at(-1)).toMatchObject({
				type: 'order.released',
				status,
				reason: null,
				releasedBy,
			});
		},
	);

	it('refuses to release an order that is not held, or by an unknown cause', async () => {
		const submitted = await placeOrder('o1');
		await control('hold', {});
		const held = await placeOrder('o2');

		const notHeld = await control('release', { orderId: submitted.id });
		const unknown = await control('release', {
			orderId: held.id,
			releasedBy: 'magic',
		});
		const missing = await control('release', { orderId: 'ord_none' });

		expect([notHeld.status, unknown.status, missing.status]).toEqual([
			400, 400, 400,
		]);
		expect(received).toEqual([]);
	});

	it('lists held orders under their status', async () => {
		await control('hold', {});
		await placeOrder('o1');

		const list = await (
			await send(fake, '/v1/orders?status=held', { headers: asStore })
		).json();

		expect(list.total).toBe(1);
		expect(list.counts.held).toBe(1);
	});
});
