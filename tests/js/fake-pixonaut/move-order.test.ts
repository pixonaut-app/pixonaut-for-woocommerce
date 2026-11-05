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
import { DEFAULT_TRACKING } from '../../fake-pixonaut/src/webhooks/move-order';
import { SITE_URL, send } from './support';

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

describe('move order', () => {
	it('emits each step from submitted to shipped, the last with tracking', async () => {
		const response = await move({
			orderId: ORDER_ID,
			printId: PRINT_ID,
			externalRef: 'wc_1042_7',
		});

		expect(response.status, await response.clone().text()).toBe(200);
		expect(received.map((event) => event.type)).toEqual([
			'order.status_changed',
			'order.status_changed',
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
		]);
		expect(received[2]?.payload).toMatchObject({
			orderId: ORDER_ID,
			printId: PRINT_ID,
			externalRef: 'wc_1042_7',
			tracking: DEFAULT_TRACKING,
		});
		expect(received[0]?.payload).not.toHaveProperty('tracking');
	});

	it('moves between the statuses given, with the tracking given', async () => {
		const tracking = { number: 'TRK1' };

		await move({
			orderId: ORDER_ID,
			printId: PRINT_ID,
			from: 'printed',
			tracking,
		});

		expect(received).toHaveLength(1);
		expect(received[0]?.payload).toMatchObject({
			previousStatus: 'printed',
			status: 'shipped',
			tracking,
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
