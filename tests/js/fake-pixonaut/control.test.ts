import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { createFake } from '../../fake-pixonaut/src/app';
import { DEFAULT_TIMEOUT_FAULT_MS } from '../../fake-pixonaut/src/faults';
import { FIXTURE } from '../../fake-pixonaut/src/fixture-ids';
import { bearer, MAIN, SITE_URL, send, templatePath } from './support';

/** A fixed instant for the clock tests. */
const FROZEN_NOW = Date.parse('2026-10-14T12:00:00Z');
const ONE_HOUR_MS = 60 * 60 * 1000;

let realNow = FROZEN_NOW;
const fake = createFake({ realNow: () => realNow });

const live = templatePath(FIXTURE.template.live);
const asStore = { headers: bearer(MAIN.integrationKey) };

function control(path: string, json?: unknown) {
	return send(fake, `/__fake/${path}`, {
		method: json === undefined ? 'GET' : 'POST',
		json,
	});
}

beforeEach(() => {
	realNow = FROZEN_NOW;
	fake.reset('default', SITE_URL);
});

describe('reset', () => {
	it('restores the fixture set and empties the logs', async () => {
		await send(fake, live, asStore);
		fake.state.templates.clear();

		const response = await control('reset', { fixture: 'default' });

		expect(response.status).toBe(200);
		expect(fake.requests).toEqual([]);
		expect(fake.state.templates.has(FIXTURE.template.live)).toBe(true);
	});

	it('refuses a fixture set it does not have', async () => {
		const response = await control('reset', { fixture: 'nope' });

		expect(response.status).toBe(400);
	});

	it('refuses a site that is not a URL', async () => {
		const response = await control('reset', { siteUrl: 'not a url' });

		expect(response.status).toBe(400);
	});

	it("lets the main store's publishable key in from the named site", async () => {
		await control('reset', { siteUrl: 'http://other-site.test:8080/shop' });

		expect(fake.state.stores.get(MAIN.id)?.allowedOrigins).toEqual([
			'http://other-site.test:8080',
		]);
	});
});

describe('control requests', () => {
	it('are refused unless they say they are JSON, and change nothing', async () => {
		fake.state.templates.clear();

		// What a page on another origin can send without a preflight.
		const response = await send(fake, '/__fake/reset', {
			method: 'POST',
			headers: { 'content-type': 'text/plain' },
		});

		expect(response.status).toBe(415);
		expect(fake.state.templates.size).toBe(0);
	});

	it('are refused when the body is not JSON', async () => {
		const response = await send(fake, '/__fake/clock', {
			method: 'POST',
			headers: { 'content-type': 'application/json' },
			body: '{not json',
		});

		expect(response.status).toBe(400);
	});

	it('send no CORS headers, so no page can read them', async () => {
		const response = await send(fake, '/__fake/requests', {
			headers: { origin: 'http://elsewhere.test' },
		});

		expect(response.headers.get('access-control-allow-origin')).toBeNull();
	});
});

describe('clock', () => {
	it('is set and advanced through the control API', async () => {
		await control('clock', { set: '2027-01-01T00:00:00Z' });
		const advanced = await control('clock', { advanceMs: ONE_HOUR_MS });

		expect((await advanced.json()).now).toBe(
			Date.parse('2027-01-01T01:00:00Z'),
		);
	});

	it('keeps ticking with real time after it is set', async () => {
		await control('clock', { set: FROZEN_NOW + ONE_HOUR_MS });
		realNow += 1;

		expect(fake.clock.now()).toBe(FROZEN_NOW + ONE_HOUR_MS + 1);
	});

	it.each([
		['a non-numeric advance', { advanceMs: 'oops' }],
		['an unreadable time', { set: 'not a time' }],
		['a good time with a bad advance', { set: FROZEN_NOW, advanceMs: null }],
	])('refuses %s and leaves the clock as it was', async (_, json) => {
		await control('clock', { advanceMs: ONE_HOUR_MS });

		const response = await control('clock', json);
		const after = await control('clock', { advanceMs: ONE_HOUR_MS });

		expect(response.status).toBe(400);
		expect((await after.json()).now).toBe(FROZEN_NOW + 2 * ONE_HOUR_MS);
	});

	it('goes back to real time on reset', async () => {
		await control('clock', { advanceMs: ONE_HOUR_MS });
		await control('reset', {});

		expect(fake.clock.now()).toBe(FROZEN_NOW);
	});
});

describe('faults', () => {
	afterEach(() => {
		vi.useRealTimers();
	});

	it('answers the given status and code for the given number of requests', async () => {
		await control('faults', {
			operation: 'GET /v1/templates/{id}',
			fault: { kind: 'status', status: 503, error: 'lock_timeout' },
			times: 1,
		});

		const faulted = await send(fake, live, asStore);
		const next = await send(fake, live, asStore);

		expect(faulted.status).toBe(503);
		expect(await faulted.json()).toEqual({ error: 'lock_timeout' });
		expect(next.status).toBe(200);
		expect(fake.violations).toEqual([]);
	});

	it('answers further fields beside the code, as a cancel refusal carries', async () => {
		await control('faults', {
			operation: 'POST /v1/orders/{id}/cancel',
			fault: {
				kind: 'status',
				status: 503,
				error: 'cancel_unavailable',
				fields: { reason: 'The printshop did not answer.', retryable: true },
			},
			times: 1,
		});

		const faulted = await send(fake, '/v1/orders/ord_any/cancel', {
			method: 'POST',
			...asStore,
		});

		expect(faulted.status).toBe(503);
		expect(await faulted.json()).toEqual({
			error: 'cancel_unavailable',
			reason: 'The printshop did not answer.',
			retryable: true,
		});
	});

	it('holds the answer for a timeout', async () => {
		vi.useFakeTimers();
		await control('faults', {
			operation: 'GET /v1/templates/{id}',
			fault: { kind: 'timeout' },
		});

		let settled = false;
		const pending = send(fake, live, asStore).then((response) => {
			settled = true;
			return response;
		});
		await vi.advanceTimersByTimeAsync(DEFAULT_TIMEOUT_FAULT_MS - 1);
		expect(settled).toBe(false);
		await vi.advanceTimersByTimeAsync(1);

		expect((await pending).status).toBe(504);
	});

	it('refuses a fault for an operation the reference does not have', async () => {
		const response = await control('faults', {
			operation: 'GET /v1/nowhere',
			fault: { kind: 'status', status: 500 },
		});

		expect(response.status).toBe(400);
	});

	it.each([
		['an unknown kind', { kind: 'slow' }],
		['a status that is not an integer', { kind: 'status', status: '503' }],
		['a status outside HTTP', { kind: 'status', status: 999 }],
		['a negative timeout', { kind: 'timeout', ms: -1 }],
		[
			'fields that are not plain values',
			{ kind: 'status', status: 409, error: 'x', fields: { cause: {} } },
		],
		['fields without a code', { kind: 'status', status: 409, fields: {} }],
	])('refuses a fault with %s', async (_, fault) => {
		const response = await control('faults', {
			operation: 'GET /v1/templates/{id}',
			fault,
		});

		expect(response.status).toBe(400);
		expect(fake.faults.list()).toEqual({});
	});

	it('refuses a count that is not a positive integer', async () => {
		const response = await control('faults', {
			operation: 'GET /v1/templates/{id}',
			fault: { kind: 'status', status: 500 },
			times: 0,
		});

		expect(response.status).toBe(400);
		expect(fake.faults.list()).toEqual({});
	});

	it('is cleared by reset', async () => {
		await control('faults', {
			operation: 'GET /v1/templates/{id}',
			fault: { kind: 'status', status: 500 },
		});
		await control('reset', {});

		expect((await send(fake, live, asStore)).status).toBe(200);
	});
});

describe('request log', () => {
	it('lists every request with its operation and status', async () => {
		await send(fake, live, asStore);
		await send(fake, '/v1/asset-categories', asStore);

		const log = await (await control('requests')).json();

		expect(log).toMatchObject([
			{ method: 'GET', operation: 'GET /v1/templates/{id}', status: 200 },
			{
				method: 'GET',
				operation: 'GET /v1/asset-categories',
				status: 501,
			},
		]);
	});
});
