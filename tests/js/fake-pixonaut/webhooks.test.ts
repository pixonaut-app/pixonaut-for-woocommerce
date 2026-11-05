import { createHmac, timingSafeEqual } from 'node:crypto';
import { readFileSync } from 'node:fs';
import { createServer, type IncomingHttpHeaders, type Server } from 'node:http';
import type { AddressInfo } from 'node:net';
import { join } from 'node:path';
import {
	afterAll,
	afterEach,
	beforeAll,
	beforeEach,
	describe,
	expect,
	it,
} from 'vitest';
import { readOverlay, withOverlay } from '../../../bin/contracts/overlay.mjs';
import { createFake } from '../../fake-pixonaut/src/app';
import { FIXTURE } from '../../fake-pixonaut/src/fixture-ids';
import { SERVED_WEBHOOKS } from '../../fake-pixonaut/src/handlers';
import { CONTRACTS_DIR } from '../../fake-pixonaut/src/spec';
import type {
	Attempt,
	Delivery,
} from '../../fake-pixonaut/src/webhooks/deliveries';
import {
	MAX_ATTEMPTS,
	shuffled,
} from '../../fake-pixonaut/src/webhooks/deliveries';
import {
	SIGNATURE_HEADER,
	sign,
	signedRequest,
	TIMESTAMP_HEADER,
} from '../../fake-pixonaut/src/webhooks/sign';
import { bearer, MAIN, SITE_URL, send } from './support';

type Vector = {
	name: string;
	secret: string;
	body: string;
	headers: Record<string, string>;
};

const VECTORS: {
	toleranceSeconds: number;
	now: number;
	valid: Vector[];
	invalid: Vector[];
} = JSON.parse(
	readFileSync(join(CONTRACTS_DIR, 'webhook-signature-vectors.json'), 'utf8'),
);

const MS_PER_SECOND = 1000;

/** How long a test waits before retrying a delivery. */
const RETRY_AFTER_S = 60;

/** A fixed instant for the fake's clock. */
const FROZEN_NOW = Date.parse('2026-10-14T12:00:00Z');

/** Verifies a request as a receiver following Pixonaut's guide would: the
 *  scheme, the HMAC over the raw body, and the timestamp within tolerance.
 *  The vectors below hold this oracle to Pixonaut's own signer. */
function verifies(
	secret: string,
	headers: Record<string, string | string[] | undefined>,
	body: string,
	nowSeconds: number,
): boolean {
	const timestamp = String(headers[TIMESTAMP_HEADER] ?? '');
	const signature = String(headers[SIGNATURE_HEADER] ?? '');
	if (!/^\d+$/.test(timestamp) || !signature.startsWith('v1=')) {
		return false;
	}
	if (Math.abs(nowSeconds - Number(timestamp)) > VECTORS.toleranceSeconds) {
		return false;
	}
	const expected = createHmac('sha256', secret)
		.update(`${timestamp}.${body}`, 'utf8')
		.digest();
	const given = Buffer.from(signature.slice('v1='.length), 'hex');
	return given.length === expected.length && timingSafeEqual(given, expected);
}

/** The reference's first example body for a webhook, the overlay's for
 *  one it stands in for. */
function example(type: string): { type: string; payload: unknown } {
	const spec = withOverlay(
		JSON.parse(readFileSync(join(CONTRACTS_DIR, 'openapi.json'), 'utf8')),
		readOverlay(),
	);
	const examples =
		spec.webhooks[type].post.requestBody.content['application/json'].examples;
	const { value } = Object.values(examples)[0] as {
		value: { type: string; payload: unknown };
	};
	return { type: value.type, payload: value.payload };
}

describe('the signer', () => {
	it.each(VECTORS.valid)('reproduces $name byte for byte', (vector) => {
		const envelope = JSON.parse(vector.body);

		const signed = signedRequest(
			vector.secret,
			envelope,
			Number(vector.headers[TIMESTAMP_HEADER]) * MS_PER_SECOND,
		);

		expect(signed.body).toBe(vector.body);
		expect(signed.headers).toEqual(vector.headers);
	});

	it.each(VECTORS.valid)('signs $name so it verifies', (vector) => {
		expect(
			verifies(vector.secret, vector.headers, vector.body, VECTORS.now),
		).toBe(true);
	});

	it.each(VECTORS.invalid)('does not verify $name', (vector) => {
		expect(
			verifies(vector.secret, vector.headers, vector.body, VECTORS.now),
		).toBe(false);
	});

	it('signs the timestamp and the body, so changing either breaks it', () => {
		const [vector] = VECTORS.valid as [Vector];
		const timestamp = Number(vector.headers[TIMESTAMP_HEADER]);
		const signature = sign(vector.secret, timestamp, vector.body);

		expect(sign(vector.secret, timestamp + 1, vector.body)).not.toBe(signature);
		expect(sign(vector.secret, timestamp, `${vector.body} `)).not.toBe(
			signature,
		);
	});
});

type Received = {
	headers: IncomingHttpHeaders;
	body: string;
};

/** A store's webhook endpoint on loopback: records every request and answers
 *  each with the next status queued, else 200. While `holding`, it records a
 *  request but keeps its answer until `release()`, so a test can act while a
 *  delivery is in flight. With `breakBody`, it sends a 200 and then breaks
 *  the connection off in the middle of the body. */
const received: Received[] = [];
const answers: number[] = [];
const held: (() => void)[] = [];
let holding = false;
let breakBody = false;
let server: Server;
let endpointUrl: string;

/** A body length the endpoint promises but never sends in full. */
const PROMISED_BODY_BYTES = 100;

/** Answers every request held back so far. */
function release(): void {
	for (const answer of held.splice(0)) {
		answer();
	}
}

/** Resolves once the endpoint has received `count` requests. */
async function receivedCount(count: number): Promise<void> {
	while (received.length < count) {
		await new Promise((resolve) => setImmediate(resolve));
	}
}

beforeAll(async () => {
	server = createServer((request, response) => {
		const chunks: Buffer[] = [];
		request.on('data', (chunk: Buffer) => chunks.push(chunk));
		request.on('end', () => {
			received.push({
				headers: request.headers,
				body: Buffer.concat(chunks).toString('utf8'),
			});
			const status = answers.shift() ?? 200;
			if (breakBody) {
				response.writeHead(status, {
					'content-length': String(PROMISED_BODY_BYTES),
				});
				response.write('partial', () => response.destroy());
				return;
			}
			const answer = () => {
				response.statusCode = status;
				response.end();
			};
			if (holding) {
				held.push(answer);
			} else {
				answer();
			}
		});
	});
	await new Promise<void>((resolve) => server.listen(0, '127.0.0.1', resolve));
	const { port } = server.address() as AddressInfo;
	endpointUrl = `http://127.0.0.1:${port}/wp-json/pixonaut/v1/webhook`;
});

afterAll(() => new Promise<void>((resolve) => server.close(() => resolve())));

let realNow = FROZEN_NOW;
const fake = createFake({ realNow: () => realNow });

function control(path: string, json?: unknown) {
	return send(fake, `/__fake/webhooks/${path}`, {
		method: json === undefined ? 'GET' : 'POST',
		json,
	});
}

async function emit(json: unknown): Promise<Attempt[]> {
	const response = await control('emit', json);
	expect(response.status, await response.clone().text()).toBe(200);
	return (await response.json()).attempts;
}

async function deliveries(): Promise<{
	endpoints: {
		storeId: string;
		id: string;
		url: string;
		active: boolean;
		failedCount: number;
		dropping: boolean;
	}[];
	deliveries: Delivery[];
	attempts: Attempt[];
}> {
	return (await control('deliveries')).json();
}

const APPROVED = example('design.approved');
const SECRET = MAIN.webhook.secret;

beforeEach(async () => {
	realNow = FROZEN_NOW;
	fake.reset('default', SITE_URL);
	received.length = 0;
	answers.length = 0;
	holding = false;
	breakBody = false;
	release();
	expect((await control('endpoint', { url: endpointUrl })).status).toBe(200);
});

afterEach(() => {
	expect(fake.violations).toEqual([]);
});

describe('endpoints', () => {
	it("are registered for each store under its site's REST address", async () => {
		fake.reset('default', SITE_URL);

		expect((await deliveries()).endpoints).toContainEqual({
			storeId: MAIN.id,
			id: MAIN.webhook.id,
			url: `${SITE_URL}/wp-json/pixonaut/v1/webhook`,
			active: true,
			failedCount: 0,
			dropping: false,
		});
	});

	it('take a URL and a secret from the control API', async () => {
		const response = await control('endpoint', {
			store: FIXTURE.store.other.id,
			url: 'http://tests-wordpress/?rest_route=/pixonaut/v1/webhook',
			secret: 'whsec_changed',
		});

		expect(await response.json()).toEqual({
			storeId: FIXTURE.store.other.id,
			id: FIXTURE.store.other.webhook.id,
			url: 'http://tests-wordpress/?rest_route=/pixonaut/v1/webhook',
			secret: 'whsec_changed',
			active: true,
			reachable: true,
			eventTypes: [],
			createdAt: FROZEN_NOW,
		});
	});

	it('refuse a URL that is not one, or a store there is not', async () => {
		expect((await control('endpoint', { url: 'not a url' })).status).toBe(400);
		expect((await control('endpoint', { store: 'conn_nope' })).status).toBe(
			400,
		);
	});
});

describe('emit', () => {
	it('delivers a signed event to the store endpoint', async () => {
		const [attempt] = await emit({ events: [APPROVED] });

		expect(received).toHaveLength(1);
		const [request] = received as [Received];
		const envelope = JSON.parse(request.body);
		expect(envelope).toEqual({
			id: expect.stringMatching(/^\d+-[0-9a-f-]{36}$/),
			type: 'design.approved',
			payload: APPROVED.payload,
			createdAt: FROZEN_NOW,
		});
		expect(request.headers['content-type']).toBe('application/json');
		expect(request.headers[TIMESTAMP_HEADER]).toBe(
			String(FROZEN_NOW / MS_PER_SECOND),
		);
		expect(
			verifies(
				SECRET,
				request.headers,
				request.body,
				FROZEN_NOW / MS_PER_SECOND,
			),
		).toBe(true);
		expect(attempt).toMatchObject({
			eventId: envelope.id,
			status: 200,
			body: request.body,
		});
		expect((await deliveries()).deliveries).toMatchObject([
			{ status: 'delivered', attempts: 1, envelope },
		]);
	});

	it('keeps the id and createdAt a test gives', async () => {
		await emit({
			events: [{ ...APPROVED, id: 'evt_given', createdAt: 1_790_000_000_000 }],
		});

		expect(JSON.parse(received[0]?.body ?? '')).toMatchObject({
			id: 'evt_given',
			createdAt: 1_790_000_000_000,
		});
	});

	it('delivers the reference example of every event it serves', async () => {
		await emit({ events: SERVED_WEBHOOKS.map(example) });

		expect(received.map((r) => JSON.parse(r.body).type)).toEqual([
			...SERVED_WEBHOOKS,
		]);
	});

	it('refuses a payload the reference does not allow, and sends nothing', async () => {
		const response = await control('emit', {
			events: [APPROVED, { type: 'design.approved', payload: { nope: 1 } }],
		});

		expect(response.status).toBe(400);
		expect((await response.json()).problems).toEqual([
			expect.stringContaining('events[1]:'),
		]);
		expect(received).toEqual([]);
	});

	it('refuses an event type it does not deliver', async () => {
		for (const type of ['webhook.ping', 'asset.created', 'nope']) {
			const response = await control('emit', {
				events: [{ type, payload: {} }],
			});

			expect(response.status).toBe(400);
		}
		expect(received).toEqual([]);
	});

	it('refuses a malformed command', async () => {
		for (const body of [
			{},
			{ events: [] },
			{ events: [{ ...APPROVED, tamper: 'everything' }] },
			{ events: [APPROVED], order: 'backwards' },
			{ events: [APPROVED], seed: 1.5 },
			{ events: [{ ...APPROVED, createdAt: 'yesterday' }] },
			{ events: [APPROVED], store: 'conn_nope' },
		]) {
			expect((await control('emit', body)).status).toBe(400);
		}
		expect(received).toEqual([]);
	});

	it("signs with the fake's clock", async () => {
		realNow = VECTORS.now * MS_PER_SECOND;

		await emit({ events: [APPROVED] });

		expect(received[0]?.headers[TIMESTAMP_HEADER]).toBe(String(VECTORS.now));
	});

	it('takes a 2xx as delivered though the body breaks off after it', async () => {
		breakBody = true;

		const [attempt] = await emit({ events: [APPROVED] });

		expect(attempt).toMatchObject({ status: 200 });
		expect((await deliveries()).deliveries).toMatchObject([
			{ status: 'delivered', attempts: 1 },
		]);
	});

	it('records a delivery the endpoint never answers as pending', async () => {
		await control('endpoint', { url: 'http://127.0.0.1:1/unreachable' });

		const [attempt] = await emit({ events: [APPROVED] });

		expect(attempt).toMatchObject({ status: null, error: expect.any(String) });
		expect((await deliveries()).deliveries).toMatchObject([
			{ status: 'pending', attempts: 1 },
		]);
	});
});

/** Enough events that a shuffle all but never keeps their order. */
const SHUFFLED_EVENTS = 8;

describe('order', () => {
	const events = Array.from({ length: SHUFFLED_EVENTS }, (_, n) => ({
		...APPROVED,
		id: `evt_${n}`,
	}));
	const ids = events.map((e) => e.id);
	const sentIds = () => received.map((r) => JSON.parse(r.body).id);

	it('is the order given by default', async () => {
		await emit({ events });

		expect(sentIds()).toEqual(ids);
	});

	it('is shuffled on request, every event delivered once', async () => {
		await emit({ events, order: 'shuffled', seed: 7 });

		expect(sentIds()).not.toEqual(ids);
		expect([...sentIds()].sort()).toEqual([...ids].sort());
	});

	it('repeats a shuffle by its seed', async () => {
		await emit({ events, order: 'shuffled', seed: 7 });
		const first = sentIds();
		received.length = 0;

		await emit({ events, order: 'shuffled', seed: 7 });

		expect(sentIds()).toEqual(first);
		expect(shuffled(ids, 7)).toEqual(first);
	});
});

describe('tamper', () => {
	it.each(['body', 'timestamp', 'secret'] as const)(
		'sends a delivery with a bad %s that does not verify',
		async (tamper) => {
			await emit({ events: [{ ...APPROVED, tamper }] });

			const [request] = received as [Received];
			expect(
				verifies(
					SECRET,
					request.headers,
					request.body,
					FROZEN_NOW / MS_PER_SECOND,
				),
			).toBe(false);
		},
	);

	it('keeps the body a JSON envelope of the same event', async () => {
		await emit({ events: [{ ...APPROVED, id: 'evt_t', tamper: 'body' }] });

		expect(JSON.parse(received[0]?.body ?? '')).toMatchObject({
			id: 'evt_t',
			type: 'design.approved',
		});
	});
});

describe('retries and replays', () => {
	it('retries a delivery that was not acknowledged, with the same id', async () => {
		answers.push(500);
		await emit({ events: [APPROVED] });
		realNow += RETRY_AFTER_S * MS_PER_SECOND;

		const response = await control('retry', {});

		expect(response.status).toBe(200);
		const [first, second] = received as [Received, Received];
		expect(second.body).toBe(first.body);
		expect(Number(second.headers[TIMESTAMP_HEADER])).toBe(
			Number(first.headers[TIMESTAMP_HEADER]) + RETRY_AFTER_S,
		);
		expect(
			verifies(SECRET, second.headers, second.body, realNow / MS_PER_SECOND),
		).toBe(true);
		expect((await deliveries()).deliveries).toMatchObject([
			{ status: 'delivered', attempts: 2 },
		]);
	});

	it('retires a pending delivery once the store disconnected', async () => {
		answers.push(503);
		await emit({ events: [APPROVED] });
		const deleted = await send(fake, '/v1/connect', {
			method: 'DELETE',
			headers: bearer(MAIN.integrationKey),
		});
		expect(deleted.status).toBe(204);

		const response = await control('retry', {});

		expect((await response.json()).attempts).toEqual([]);
		expect(received).toHaveLength(1);
		expect((await deliveries()).deliveries).toMatchObject([
			{ status: 'retired', attempts: 1 },
		]);
	});

	it('leaves a delivered event alone on retry', async () => {
		await emit({ events: [APPROVED] });

		await control('retry', {});

		expect(received).toHaveLength(1);
	});

	it(`fails a delivery after ${MAX_ATTEMPTS} attempts and counts it on the endpoint`, async () => {
		answers.push(...Array(MAX_ATTEMPTS).fill(503));
		await emit({ events: [APPROVED] });
		// One retry more than it takes: a failed delivery is not sent again.
		for (let retry = 0; retry < MAX_ATTEMPTS; retry++) {
			await control('retry', {});
		}

		expect(received).toHaveLength(MAX_ATTEMPTS);
		const state = await deliveries();
		expect(state.deliveries).toMatchObject([
			{ status: 'failed', attempts: MAX_ATTEMPTS },
		]);
		expect(
			state.endpoints.find((e) => e.storeId === MAIN.id)?.failedCount,
		).toBe(1);
	});

	it('replays an emitted event unchanged, outside its attempts', async () => {
		await emit({ events: [{ ...APPROVED, id: 'evt_once' }] });

		const [replayed] = await emit({ events: [{ replay: 'evt_once' }] });

		expect(received[1]?.body).toBe(received[0]?.body);
		expect(replayed).toMatchObject({ replay: true, status: 200 });
		expect((await deliveries()).deliveries).toMatchObject([
			{ status: 'delivered', attempts: 1 },
		]);
	});

	it('does not send a delivery again while an attempt is sending it', async () => {
		answers.push(500);
		await emit({ events: [APPROVED] });
		holding = true;

		const first = control('retry', {});
		await receivedCount(2);
		const second = await (await control('retry', {})).json();
		release();
		const firstAttempts = (await (await first).json()).attempts;

		expect(second.attempts).toEqual([]);
		expect(firstAttempts).toMatchObject([{ status: 200 }]);
		expect(received).toHaveLength(2);
		expect((await deliveries()).deliveries).toMatchObject([
			{ status: 'delivered', attempts: 2 },
		]);
	});

	it('does not retry a delivery its emit is still sending', async () => {
		answers.push(500);
		holding = true;

		const emitted = emit({ events: [APPROVED] });
		await receivedCount(1);
		const retried = await (await control('retry', {})).json();
		release();
		await emitted;

		expect(retried.attempts).toEqual([]);
		expect(received).toHaveLength(1);
		expect((await deliveries()).deliveries).toMatchObject([
			{ status: 'pending', attempts: 1 },
		]);
	});

	it('refuses to replay an event it did not emit to that store', async () => {
		await emit({ events: [{ ...APPROVED, id: 'evt_main' }] });

		const response = await control('emit', {
			store: FIXTURE.store.other.id,
			events: [{ replay: 'evt_main' }],
		});

		expect(response.status).toBe(400);
		expect(received).toHaveLength(1);
	});

	it('forgets every delivery on reset', async () => {
		await emit({ events: [APPROVED] });

		await send(fake, '/__fake/reset', { method: 'POST', json: {} });

		const state = await deliveries();
		expect(state.deliveries).toEqual([]);
		expect(state.attempts).toEqual([]);
	});

	it('stops an emit under way on reset, sending and recording nothing more', async () => {
		holding = true;
		const emitted = control('emit', {
			events: [
				{ ...APPROVED, id: 'evt_1' },
				{ ...APPROVED, id: 'evt_2' },
				{ ...APPROVED, id: 'evt_3' },
			],
		});
		await receivedCount(1);

		await send(fake, '/__fake/reset', { method: 'POST', json: {} });
		release();
		await emitted;

		expect(received).toHaveLength(1);
		const state = await deliveries();
		expect(state.deliveries).toEqual([]);
		expect(state.attempts).toEqual([]);
	});
});

describe("the store's own endpoint", () => {
	const asStore = { headers: bearer(MAIN.integrationKey) };
	const path = `/v1/webhooks/${MAIN.webhook.id}`;

	it('is the only one its integration key lists', async () => {
		const response = await send(fake, '/v1/webhooks', asStore);

		expect(response.status).toBe(200);
		expect(await response.json()).toEqual([
			expect.objectContaining({
				id: MAIN.webhook.id,
				url: endpointUrl,
				eventTypes: [],
				active: true,
				failing: false,
				reachable: true,
				failedCount: 0,
			}),
		]);
	});

	it('receives only the event types it is narrowed to', async () => {
		const narrowed = await send(fake, path, {
			method: 'PATCH',
			json: { eventTypes: ['order.status_changed'] },
			...asStore,
		});

		const attempts = await emit({ events: [APPROVED] });

		expect(narrowed.status).toBe(200);
		expect((await narrowed.json()).eventTypes).toEqual([
			'order.status_changed',
		]);
		expect(attempts).toEqual([]);
		expect(received).toEqual([]);
	});

	it('refuses webhook.ping as an event type and changes nothing', async () => {
		const refused = await send(fake, path, {
			method: 'PATCH',
			json: { eventTypes: ['order.status_changed', 'webhook.ping'] },
			...asStore,
		});
		const listed = await (await send(fake, '/v1/webhooks', asStore)).json();

		expect(refused.status).toBe(400);
		expect(await refused.json()).toEqual({
			error: 'invalid_event_type',
			type: 'webhook.ping',
		});
		expect(listed[0].eventTypes).toEqual([]);
	});

	it("keeps a live store's address on its site's origin", async () => {
		const store = fake.state.stores.get(MAIN.id);
		if (store === undefined) {
			throw new Error('No main store.');
		}
		const moved = `${new URL(store.siteUrl).origin}/elsewhere`;

		const refused = await send(fake, path, {
			method: 'PATCH',
			json: { url: 'https://tunnel.example/wp-json/pixonaut/v1/webhook' },
			...asStore,
		});
		const taken = await send(fake, path, {
			method: 'PATCH',
			json: { url: moved },
			...asStore,
		});

		expect(refused.status).toBe(400);
		expect(await refused.json()).toEqual({ error: 'invalid_webhook_url' });
		expect(taken.status).toBe(200);
		expect((await taken.json()).url).toBe(moved);
		expect(fake.violations).toEqual([]);
	});

	it('takes any origin for a sandbox store, which then reads as reachable', async () => {
		const store = fake.state.stores.get(MAIN.id);
		const tenant = fake.state.tenants.get(store?.tenantId ?? '');
		if (store === undefined || tenant === undefined) {
			throw new Error('No main store.');
		}
		tenant.environment = 'sandbox';
		store.webhook.reachable = false;
		const tunnel = 'https://tunnel.example/wp-json/pixonaut/v1/webhook';

		const listed = (
			await (await send(fake, '/v1/webhooks', asStore)).json()
		)[0];
		const taken = await send(fake, path, {
			method: 'PATCH',
			json: { url: tunnel },
			...asStore,
		});

		expect(listed).toMatchObject({ reachable: false, failing: false });
		expect(taken.status).toBe(200);
		expect(await taken.json()).toMatchObject({ url: tunnel, reachable: true });
		expect(fake.violations).toEqual([]);
	});

	it('refuses an address that is not a web address', async () => {
		const store = fake.state.stores.get(MAIN.id);
		const tenant = fake.state.tenants.get(store?.tenantId ?? '');
		if (tenant === undefined) {
			throw new Error('No main store.');
		}
		tenant.environment = 'sandbox';

		const refused = await send(fake, path, {
			method: 'PATCH',
			json: { url: 'ftp://tunnel.example/hook' },
			...asStore,
		});

		expect(refused.status).toBe(400);
		expect(await refused.json()).toEqual({ error: 'invalid_webhook_url' });
	});

	it("answers 404 for another store's endpoint", async () => {
		const response = await send(
			fake,
			`/v1/webhooks/${FIXTURE.store.other.webhook.id}/ping`,
			{ method: 'POST', ...asStore },
		);

		expect(response.status).toBe(404);
		expect(received).toEqual([]);
	});

	it('pings it signed, whatever it is narrowed to, and says whether it was delivered', async () => {
		await send(fake, path, {
			method: 'PATCH',
			json: { eventTypes: ['order.status_changed'] },
			...asStore,
		});
		answers.push(200, 404);

		const delivered = await send(fake, `${path}/ping`, {
			method: 'POST',
			...asStore,
		});
		const refused = await send(fake, `${path}/ping`, {
			method: 'POST',
			...asStore,
		});

		expect(await delivered.json()).toEqual({ delivered: true });
		expect(await refused.json()).toEqual({
			delivered: false,
			error: 'The endpoint answered 404.',
		});
		const [ping] = received as [Received];
		expect(JSON.parse(ping.body)).toMatchObject({
			type: 'webhook.ping',
			payload: { endpointId: MAIN.webhook.id },
		});
		expect(
			verifies(SECRET, ping.headers, ping.body, FROZEN_NOW / MS_PER_SECOND),
		).toBe(true);
		// A ping is no delivery: never retried, never failed.
		expect((await deliveries()).deliveries).toEqual([]);
	});
});

/** Emits `APPROVED` to the main store and has the endpoint refuse every
 *  attempt, so its delivery fails for good. */
async function failForGood(): Promise<void> {
	answers.push(...Array(MAX_ATTEMPTS).fill(503));
	await emit({ events: [APPROVED] });
	for (let retry = 1; retry < MAX_ATTEMPTS; retry++) {
		await control('retry', {});
	}
}

function redeliver(endpointId: string, key = MAIN.integrationKey) {
	return send(
		fake,
		`/v1/webhooks/${encodeURIComponent(endpointId)}/deliveries/redeliver-failed`,
		{ method: 'POST', headers: bearer(key) },
	);
}

describe('GET /v1/webhooks', () => {
	it("answers the store's endpoint with what failed for good", async () => {
		await failForGood();

		const response = await send(fake, '/v1/webhooks', {
			headers: bearer(MAIN.integrationKey),
		});

		expect(response.status).toBe(200);
		expect(await response.json()).toEqual([
			{
				id: MAIN.webhook.id,
				tenantId: FIXTURE.tenant.live,
				url: endpointUrl,
				eventTypes: [],
				active: true,
				cursor: null,
				createdAt: FROZEN_NOW,
				apiKeyId: null,
				failing: true,
				reachable: true,
				failedCount: 1,
				connectionId: MAIN.id,
			},
		]);
	});

	it('answers a store with no failed delivery as not failing', async () => {
		await emit({ events: [APPROVED] });

		const response = await send(fake, '/v1/webhooks', {
			headers: bearer(FIXTURE.store.other.integrationKey),
		});

		expect(await response.json()).toMatchObject([
			{ id: FIXTURE.store.other.webhook.id, failing: false, failedCount: 0 },
		]);
	});
});

describe('POST /v1/webhooks/{id}/deliveries/redeliver-failed', () => {
	it('requeues what failed for good, for the next retry to send', async () => {
		await failForGood();

		const response = await redeliver(MAIN.webhook.id);

		expect(response.status).toBe(202);
		expect(await response.json()).toEqual({ requeued: 1 });
		expect((await deliveries()).deliveries).toMatchObject([
			{ status: 'pending', attempts: 0 },
		]);
		// Nothing is sent until a retry.
		expect(received).toHaveLength(MAX_ATTEMPTS);

		await control('retry', {});

		expect(received).toHaveLength(MAX_ATTEMPTS + 1);
		expect(received[MAX_ATTEMPTS]?.body).toBe(received[0]?.body);
		const state = await deliveries();
		expect(state.deliveries).toMatchObject([
			{ status: 'delivered', attempts: 1 },
		]);
		expect(state.endpoints.find((e) => e.id === MAIN.webhook.id)).toMatchObject(
			{ failedCount: 0 },
		);
	});

	it('requeues nothing when nothing failed', async () => {
		await emit({ events: [APPROVED] });

		const response = await redeliver(MAIN.webhook.id);

		expect(await response.json()).toEqual({ requeued: 0 });
	});

	it("refuses another store's endpoint", async () => {
		const response = await redeliver(FIXTURE.store.other.webhook.id);

		expect(response.status).toBe(404);
		expect(await response.json()).toEqual({ error: 'not_found' });
	});

	it('refuses an inactive endpoint', async () => {
		const store = fake.state.stores.get(MAIN.id);
		if (store === undefined) {
			throw new Error('No main store.');
		}
		store.webhook.active = false;

		const response = await redeliver(MAIN.webhook.id);

		expect(response.status).toBe(409);
		expect(await response.json()).toEqual({ error: 'endpoint_inactive' });
	});

	it('refuses an endpoint whose address did not answer', async () => {
		await control('endpoint', { reachable: false });

		const response = await redeliver(MAIN.webhook.id);

		expect(response.status).toBe(409);
		expect(await response.json()).toEqual({ error: 'endpoint_unreachable' });
	});
});

describe('dropping deliveries', () => {
	it('fails an emitted delivery at once, sending nothing', async () => {
		const dropped = await control('drop', { drop: true });
		expect(await dropped.json()).toEqual({ storeId: MAIN.id, dropping: true });

		const attempts = await emit({ events: [APPROVED] });

		expect(attempts).toEqual([]);
		expect(received).toEqual([]);
		const state = await deliveries();
		expect(state.deliveries).toMatchObject([{ status: 'failed', attempts: 0 }]);
		expect(state.endpoints.find((e) => e.id === MAIN.webhook.id)).toMatchObject(
			{ failedCount: 1, dropping: true },
		);
	});

	it('drops only the store named', async () => {
		await control('drop', { store: FIXTURE.store.other.id, drop: true });

		await emit({ events: [APPROVED] });

		expect(received).toHaveLength(1);
	});

	it('still sends a requeued delivery on retry', async () => {
		await control('drop', { drop: true });
		await emit({ events: [APPROVED] });
		await redeliver(MAIN.webhook.id);

		await control('retry', {});

		expect(received).toHaveLength(1);
	});

	it('delivers again once dropping stops, and stops on reset', async () => {
		await control('drop', { drop: true });
		await control('drop', { drop: false });
		await emit({ events: [APPROVED] });
		expect(received).toHaveLength(1);

		await control('drop', { drop: true });
		await send(fake, '/__fake/reset', { method: 'POST', json: {} });

		expect(
			(await deliveries()).endpoints.every((e) => e.dropping === false),
		).toBe(true);
	});

	it('refuses a malformed command', async () => {
		for (const body of [{}, { drop: 'yes' }, { drop: true, store: 'nope' }]) {
			expect((await control('drop', body)).status).toBe(400);
		}
	});
});
