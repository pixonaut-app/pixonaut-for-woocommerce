import { beforeEach, describe, expect, it } from 'vitest';
import { createFake } from '../../fake-pixonaut/src/app';
import { FIXTURE } from '../../fake-pixonaut/src/fixture-ids';
import { findOwned, putOwned } from '../../fake-pixonaut/src/state';
import {
	bearer,
	FOREIGN_ORIGIN,
	MAIN,
	SITE_ORIGIN,
	SITE_URL,
	send,
	templatePath,
} from './support';

const fake = createFake({
	handlers: {
		// Takes an integration or secret key only, per the reference.
		'GET /v1/designs/{id}': () => ({
			status: 404,
			body: { error: 'not_found' },
		}),
	},
});

beforeEach(() => {
	fake.reset('default', SITE_URL);
});

const live = templatePath(FIXTURE.template.live);

describe('credential per operation', () => {
	it('refuses a request without a key', async () => {
		const response = await send(fake, live);

		expect(response.status).toBe(401);
		expect(await response.json()).toEqual({ error: 'unauthorized' });
	});

	it('refuses a key nothing issued', async () => {
		const response = await send(fake, live, {
			headers: bearer('ik_live_unknown'),
		});

		expect(response.status).toBe(401);
	});

	it('takes an integration key on the store server, with no origin', async () => {
		const response = await send(fake, live, {
			headers: bearer(MAIN.integrationKey),
		});

		expect(response.status).toBe(200);
	});

	it('takes a publishable key from an origin its store allows', async () => {
		const response = await send(fake, live, {
			headers: { ...bearer(MAIN.publishableKey), origin: SITE_ORIGIN },
		});

		expect(response.status).toBe(200);
	});

	it.each([
		['another origin', { origin: FOREIGN_ORIGIN }],
		['no origin', {}],
	])('refuses a publishable key from %s', async (_, origin) => {
		const response = await send(fake, live, {
			headers: { ...bearer(MAIN.publishableKey), ...origin },
		});

		expect(response.status).toBe(403);
		expect(await response.json()).toEqual({ error: 'origin_forbidden' });
	});

	it('refuses a key kind the operation does not take', async () => {
		const response = await send(fake, '/v1/designs/d1', {
			headers: { ...bearer(MAIN.publishableKey), origin: SITE_ORIGIN },
		});

		expect(response.status).toBe(403);
		expect(await response.json()).toEqual({ error: 'forbidden' });
	});

	it('takes a share link for its own template only', async () => {
		const own = await send(fake, live, {
			headers: bearer(FIXTURE.shareLink),
		});
		const other = await send(fake, templatePath(FIXTURE.template.previous), {
			headers: bearer(FIXTURE.shareLink),
		});

		expect(own.status).toBe(200);
		expect(other.status).toBe(404);
	});

	it('refuses a share link sent from a page outside its store', async () => {
		const response = await send(fake, live, {
			headers: { ...bearer(FIXTURE.shareLink), origin: FOREIGN_ORIGIN },
		});

		expect(response.status).toBe(403);
	});

	it('refuses a share link once it has expired', async () => {
		const link = fake.state.shareLinks.get(FIXTURE.shareLink);
		fake.clock.set((link?.expiresAt ?? 0) + 1);

		const response = await send(fake, live, {
			headers: bearer(FIXTURE.shareLink),
		});

		expect(response.status).toBe(401);
	});

	it('answers CORS preflight for the headers a storefront sends', async () => {
		const response = await send(fake, live, {
			method: 'OPTIONS',
			headers: {
				origin: SITE_ORIGIN,
				'access-control-request-method': 'POST',
				'access-control-request-headers': 'authorization,idempotency-key',
			},
		});

		expect(response.status).toBe(204);
		expect(response.headers.get('access-control-allow-origin')).toBe(
			SITE_ORIGIN,
		);
		expect(response.headers.get('access-control-allow-headers')).toContain(
			'idempotency-key',
		);
	});
});

describe('isolation', () => {
	it("answers another tenant's template as not found", async () => {
		const response = await send(fake, live, {
			headers: bearer(FIXTURE.store.sandbox.integrationKey),
		});

		expect(response.status).toBe(404);
	});

	it("finds a store's own record and not another store's", () => {
		putOwned(fake.state, 'designs', 'd1', MAIN.id, { id: 'd1' });

		expect(findOwned(fake.state, 'designs', 'd1', MAIN.id)).toEqual({
			id: 'd1',
		});
		expect(
			findOwned(fake.state, 'designs', 'd1', FIXTURE.store.other.id),
		).toBeUndefined();
	});
});
