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
import { DEFAULT_TRACKING } from '../../fake-pixonaut/src/webhooks/move-order';
import { bearer, MAIN, SITE_URL, seedPrint, send } from './support';

const ORDER_ID = 'e1f2a3b4-c5d6-4e7f-8a9b-0c1d2e3f4a5b';
const PRINT_ID = 'c4d5e6f7-a8b9-4c0d-9e1f-2a3b4c5d6e7f';

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

function move(json: unknown): Promise<Response> {
	return send(fake, '/__fake/orders/move', { method: 'POST', json });
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

describe('move order of an order the fake did not place', () => {
	it('emits each step of its item from submitted to shipped, then the order shipped', async () => {
		const response = await move({
			orderId: ORDER_ID,
			printId: PRINT_ID,
			externalRef: 'wc_1042',
		});

		expect(response.status, await response.clone().text()).toBe(200);
		expect(received.map((event) => event.type)).toEqual([
			'item.status_changed',
			'item.status_changed',
			'item.status_changed',
			'order.status_changed',
		]);
		expect(
			received.map((event) => [
				event.payload.previousStatus,
				event.payload.status,
			]),
		).toEqual([
			['submitted', 'queued'],
			['queued', 'printed'],
			['printed', 'shipped'],
			['open', 'shipped'],
		]);
		expect(received[0]?.payload).toEqual({
			orderId: ORDER_ID,
			externalRef: 'wc_1042',
			itemId: `itm_${ORDER_ID}`,
			itemExternalRef: null,
			submissionId: `sub_${ORDER_ID}`,
			printIds: [PRINT_ID],
			previousStatus: 'submitted',
			status: 'queued',
			via: 'integration',
		});
		expect(received[3]?.payload).toEqual({
			orderId: ORDER_ID,
			externalRef: 'wc_1042',
			previousStatus: 'open',
			status: 'shipped',
			via: 'integration',
		});
	});

	it('moves between the statuses given, the item named', async () => {
		await move({
			orderId: ORDER_ID,
			itemId: 'itm_1',
			itemExternalRef: 'wc_1042_7',
			from: 'queued',
			to: 'printed',
		});

		expect(received).toHaveLength(1);
		expect(received[0]?.payload).toMatchObject({
			itemId: 'itm_1',
			itemExternalRef: 'wc_1042_7',
			printIds: [],
			previousStatus: 'queued',
			status: 'printed',
			externalRef: null,
		});
	});

	it('shuffles the steps on request', async () => {
		await move({
			orderId: ORDER_ID,
			printId: PRINT_ID,
			order: 'shuffled',
			seed: 3,
		});

		expect(received.map((event) => event.payload.status).sort()).toEqual([
			'printed',
			'queued',
			'shipped',
			'shipped',
		]);
	});

	it.each([
		[{ printId: PRINT_ID }, '`orderId` is required'],
		[{ orderId: ORDER_ID }, 'did not place'],
		[{ orderId: ORDER_ID, printId: PRINT_ID, from: 'shipped' }, 'after'],
		[{ orderId: ORDER_ID, printId: PRINT_ID, to: 'failed' }, 'after'],
		[{ orderId: ORDER_ID, printId: PRINT_ID, tracking: {} }, 'number'],
		[{ orderId: ORDER_ID, printId: PRINT_ID, store: 'nope' }, 'no store'],
	])('refuses %j', async (json, message) => {
		const response = await move(json);

		expect(response.status).toBe(400);
		expect((await response.json()).error).toContain(message);
		expect(received).toEqual([]);
	});
});

describe('move order of an order the fake placed', () => {
	type Placed = {
		id: string;
		items: { id: string; submissions: { id: string }[] }[];
	};

	/** Places one order of two items, `wc-42-1` and `wc-42-2`. */
	async function placed(): Promise<Placed> {
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
		const printId = seedPrint(fake, 'dsn_1');
		const response = await send(fake, '/v1/orders', {
			method: 'POST',
			headers: { ...asStore, 'idempotency-key': 'o1' },
			json: {
				externalRef: 'wc-42',
				items: [1, 2].map((line) => ({
					externalRef: `wc-42-${line}`,
					prints: [{ printId }],
					variant: FIXTURE.variant.teeWhiteS,
					reorder: true,
				})),
			},
		});
		expect(response.status).toBe(201);
		return response.json();
	}

	async function read(id: string) {
		return (await send(fake, `/v1/orders/${id}`, { headers: asStore })).json();
	}

	it('ships one item named by its reference: the order is partly shipped, then shipped', async () => {
		const order = await placed();
		const [first, second] = order.items;

		await move({ orderId: order.id, itemExternalRef: 'wc-42-1' });

		expect(received.map((event) => event.type)).toEqual([
			'item.status_changed',
			'item.status_changed',
			'item.status_changed',
			'order.status_changed',
		]);
		expect(received[0]?.payload).toMatchObject({
			orderId: order.id,
			externalRef: 'wc-42',
			itemId: first?.id,
			itemExternalRef: 'wc-42-1',
			submissionId: first?.submissions[0]?.id,
		});
		expect(received[3]?.payload).toMatchObject({
			previousStatus: 'open',
			status: 'partly_shipped',
		});
		const partly = await read(order.id);
		expect(partly).toMatchObject({
			status: 'partly_shipped',
			items: [
				{ status: 'shipped', shippedQuantity: 1 },
				{ status: 'submitted', shippedQuantity: 0 },
			],
			parcels: [
				{
					status: 'shipped',
					tracking: DEFAULT_TRACKING,
					items: [
						{ itemId: first?.id, quantity: 1, ofQuantity: 1, whole: true },
					],
				},
			],
		});

		received.length = 0;
		const tracking = { number: 'TRK2' };
		await move({ orderId: order.id, itemId: second?.id, tracking });

		expect(received.at(-1)?.payload).toMatchObject({
			previousStatus: 'partly_shipped',
			status: 'shipped',
		});
		const shipped = await read(order.id);
		expect(shipped.status).toBe('shipped');
		expect(
			shipped.parcels.map((p: { tracking: unknown }) => p.tracking),
		).toEqual([DEFAULT_TRACKING, tracking]);
	});

	it('moves every item of the order when none is named, each step item by item', async () => {
		const order = await placed();

		await move({ orderId: order.id, to: 'queued' });

		expect(received.map((event) => event.type)).toEqual([
			'item.status_changed',
			'item.status_changed',
		]);
		expect(
			(await read(order.id)).items.map((i: { status: string }) => i.status),
		).toEqual(['queued', 'queued']);
	});

	it('refuses to pick an item the order does not hold', async () => {
		const order = await placed();

		const response = await move({ orderId: order.id, itemId: 'itm_none' });

		expect(response.status).toBe(400);
		expect(received).toEqual([]);
	});
});
