import { createHash, randomBytes } from 'node:crypto';
import { beforeEach, describe, expect, it } from 'vitest';
import { createFake } from '../../fake-pixonaut/src/app';
import { FIXTURE } from '../../fake-pixonaut/src/fixture-ids';
import { MAX_ADDITIONAL_ORIGINS } from '../../fake-pixonaut/src/handlers/connect';
import { CONNECT_CODE_LIFETIME_MS } from '../../fake-pixonaut/src/studio';
import { bearer, MAIN, SITE_URL, send } from './support';

const fake = createFake();

beforeEach(() => {
	fake.reset('default', SITE_URL);
});

const REDIRECT_URI = `${SITE_URL}/wp-admin/admin-post.php?action=pixonaut_wc_connect_return`;

/** RFC 7636's 32 random bytes, base64url. */
const VERIFIER_BYTES = 32;

function pkce(): { verifier: string; challenge: string } {
	const verifier = randomBytes(VERIFIER_BYTES).toString('base64url');
	const challenge = createHash('sha256').update(verifier).digest('base64url');
	return { verifier, challenge };
}

type Given = {
	connectionId: string;
	environment: string;
	integrationKey: { key: string; label: string };
};

/** Sends a merchant to the consent screen and reads where they are sent. */
async function consent(
	params: Record<string, string>,
	omit: string[] = [],
): Promise<{ status: number; query: URLSearchParams }> {
	const query = new URLSearchParams({
		client: 'woocommerce',
		site_url: SITE_URL,
		redirect_uri: REDIRECT_URI,
		state: 'n_7f3a9c',
		code_challenge_method: 'S256',
		site_name: 'Acme Shop',
		...params,
	});
	for (const name of omit) {
		query.delete(name);
	}
	const response = await send(fake, `/connect?${query}`);
	const location = response.headers.get('location');
	return {
		status: response.status,
		query: new URL(location ?? 'http://none.test').searchParams,
	};
}

function exchange(body: Record<string, string | undefined>): Promise<Response> {
	return send(fake, '/v1/connect/token', { method: 'POST', json: body });
}

/** Connects the main site end to end; answers the token body. */
async function connect(): Promise<Given> {
	const { verifier, challenge } = pkce();
	const { query } = await consent({ code_challenge: challenge });
	const response = await exchange({
		code: query.get('code') ?? '',
		code_verifier: verifier,
		redirect_uri: REDIRECT_URI,
	});
	expect(response.status).toBe(201);
	return response.json();
}

describe('consent screen', () => {
	it('sends the merchant back with a code and the state', async () => {
		const { status, query } = await consent({
			code_challenge: pkce().challenge,
		});

		expect(status).toBe(302);
		expect(query.get('action')).toBe('pixonaut_wc_connect_return');
		expect(query.get('state')).toBe('n_7f3a9c');
		expect(query.get('code')).toMatch(/^c_/);
		expect(fake.violations).toEqual([]);
	});

	it('sends access_denied when the member cancels', async () => {
		fake.state.consent.decision = 'deny';

		const { query } = await consent({ code_challenge: pkce().challenge });

		expect(query.get('error')).toBe('access_denied');
		expect(query.get('state')).toBe('n_7f3a9c');
		expect(query.has('code')).toBe(false);
	});

	it.each<[Record<string, string>, string[], string]>([
		[{ client: 'shopify' }, [], 'client'],
		[{ site_url: `${SITE_URL}/?lang=sv` }, [], 'site_url'],
		[{ redirect_uri: 'http://elsewhere.test/back' }, [], 'redirect_uri'],
		[{ rest_url: 'http://elsewhere.test/wp-json/' }, [], 'rest_url'],
		[{ code_challenge: 'plain-verifier' }, [], 'code_challenge'],
		[{ code_challenge_method: 'plain' }, [], 'code_challenge_method'],
		[{ environment: 'staging' }, [], 'environment'],
		[{}, ['state'], 'state'],
	])(
		'refuses %o without %o as a violation of %s',
		async (params, omit, field) => {
			const { status } = await consent(
				{ code_challenge: pkce().challenge, ...params },
				omit,
			);

			expect(status).toBe(400);
			expect(fake.violations).toMatchObject([
				{
					operation: 'GET /connect (studio)',
					message: expect.stringContaining(field),
				},
			]);
		},
	);
});

describe('code exchange', () => {
	it('answers the keys, which then read the connection', async () => {
		const given = await connect();

		expect(given).toMatchObject({
			tenantId: FIXTURE.tenant.live,
			tenantSlug: FIXTURE.tenantSlug.live,
			environment: 'live',
			integrationKey: {
				key: expect.stringMatching(/^ik_live_/),
				label: 'WooCommerce · Acme Shop (shop.test)',
			},
			publishableKey: {
				key: expect.stringMatching(/^pk_live_/),
				allowedOrigins: [SITE_URL],
			},
			webhook: {
				url: `${SITE_URL}/wp-json/pixonaut/v1/webhook`,
				secret: expect.stringMatching(/^whsec_/),
			},
		});
		const read = await send(fake, '/v1/connect', {
			headers: bearer(given.integrationKey.key),
		});
		expect(read.status).toBe(200);
		expect(await read.json()).toMatchObject({
			connectionId: given.connectionId,
			name: given.integrationKey.label,
			siteUrl: SITE_URL,
			tenantName: 'Fixture Prints',
			tenantSlug: FIXTURE.tenantSlug.live,
			environment: 'live',
			reachable: true,
			webhook: {
				id: expect.any(String),
				url: `${SITE_URL}/wp-json/pixonaut/v1/webhook`,
				active: true,
				reachable: true,
			},
		});
		expect(fake.violations).toEqual([]);
	});

	it('gives sandbox keys when the member picks the sandbox', async () => {
		fake.state.consent.environment = 'sandbox';

		const given = await connect();

		expect(given.environment).toBe('sandbox');
		expect(given.integrationKey.key).toMatch(/^ik_test_/);
	});

	it('replaces the connection of a connected site, keeping its store', async () => {
		const given = await connect();

		const old = await send(fake, '/v1/connect', {
			headers: bearer(MAIN.integrationKey),
		});

		expect(old.status).toBe(401);
		expect(fake.state.stores.get(MAIN.id)?.integrationKey).toBe(
			given.integrationKey.key,
		);
	});

	it.each<[string, Record<string, string | undefined>]>([
		['a wrong verifier', { code_verifier: pkce().verifier }],
		['another redirect_uri', { redirect_uri: `${SITE_URL}/elsewhere` }],
		['no verifier', { code_verifier: undefined }],
	])(
		'refuses %s with invalid_grant and uses the code up',
		async (_, change) => {
			const { verifier, challenge } = pkce();
			const { query } = await consent({ code_challenge: challenge });
			const body = {
				code: query.get('code') ?? '',
				code_verifier: verifier,
				redirect_uri: REDIRECT_URI,
			};

			const refused = await exchange({ ...body, ...change });
			const again = await exchange(body);

			expect(refused.status).toBe(400);
			expect(await refused.json()).toEqual({ error: 'invalid_grant' });
			expect(again.status).toBe(400);
		},
	);

	it('refuses a code after its minute', async () => {
		const { verifier, challenge } = pkce();
		const { query } = await consent({ code_challenge: challenge });
		fake.clock.advance(CONNECT_CODE_LIFETIME_MS);

		const response = await exchange({
			code: query.get('code') ?? '',
			code_verifier: verifier,
			redirect_uri: REDIRECT_URI,
		});

		expect(response.status).toBe(400);
	});
});

describe('disconnect', () => {
	it('revokes the keys', async () => {
		const given = await connect();
		const asStore = { headers: bearer(given.integrationKey.key) };

		const deleted = await send(fake, '/v1/connect', {
			method: 'DELETE',
			...asStore,
		});
		const read = await send(fake, '/v1/connect', asStore);

		expect(deleted.status).toBe(204);
		expect(read.status).toBe(401);
		expect(fake.violations).toEqual([]);
	});

	it('stops webhook deliveries to the store', async () => {
		const deleted = await send(fake, '/v1/connect', {
			method: 'DELETE',
			headers: bearer(MAIN.integrationKey),
		});

		const emitted = await fake.webhooks.emit(MAIN.id, [
			{ type: 'design.approved', payload: {} },
		]);

		expect(deleted.status).toBe(204);
		expect(emitted).toEqual({
			ok: false,
			problems: [`store ${MAIN.id} disconnected; its endpoint is revoked`],
		});
		expect(fake.webhooks.attempts).toEqual([]);
	});
});

describe('origins', () => {
	const asStore = { headers: bearer(MAIN.integrationKey) };

	it("adds the site's www. counterpart to the publishable key, and takes it away", async () => {
		const added = await send(fake, '/v1/connect', {
			method: 'PATCH',
			json: { additionalOrigins: ['http://www.shop.test'] },
			...asStore,
		});
		const removed = await send(fake, '/v1/connect', {
			method: 'PATCH',
			json: { additionalOrigins: [] },
			...asStore,
		});

		expect(added.status).toBe(200);
		expect((await added.json()).allowedOrigins).toEqual([
			'http://shop.test',
			'http://www.shop.test',
		]);
		expect((await removed.json()).allowedOrigins).toEqual(['http://shop.test']);
		expect(fake.violations).toEqual([]);
	});

	it("adds any host of the site's own domain", async () => {
		const added = await send(fake, '/v1/connect', {
			method: 'PATCH',
			json: {
				additionalOrigins: [
					'http://www.shop.test',
					'http://checkout.shop.test',
				],
			},
			...asStore,
		});

		expect((await added.json()).allowedOrigins).toEqual([
			'http://shop.test',
			'http://www.shop.test',
			'http://checkout.shop.test',
		]);
	});

	it('adds the www. counterpart of a single-label host', async () => {
		fake.reset('default', 'http://localhost:8872');
		const added = await send(fake, '/v1/connect', {
			method: 'PATCH',
			json: { additionalOrigins: ['http://www.localhost:8872'] },
			...asStore,
		});

		expect(added.status).toBe(200);
	});

	it.each([
		['another scheme', 'https://www.shop.test'],
		['another domain', 'http://www.other.test'],
		['another port', 'http://www.shop.test:8080'],
	])('refuses an origin on %s', async (_, origin) => {
		const refused = await send(fake, '/v1/connect', {
			method: 'PATCH',
			json: { additionalOrigins: [origin] },
			...asStore,
		});

		expect(refused.status).toBe(400);
		expect(await refused.json()).toEqual({ error: 'invalid_origin', origin });
		expect(fake.violations).toEqual([]);
	});

	it('refuses one origin past the limit', async () => {
		const origins = Array.from(
			{ length: MAX_ADDITIONAL_ORIGINS + 1 },
			(_, index) => `http://host${index}.shop.test`,
		);

		const refused = await send(fake, '/v1/connect', {
			method: 'PATCH',
			json: { additionalOrigins: origins },
			...asStore,
		});

		expect(refused.status).toBe(400);
		expect(await refused.json()).toEqual({
			error: 'too_many_origins',
			max: MAX_ADDITIONAL_ORIGINS,
		});
	});
});

describe('workspace settings', () => {
	const asStore = { headers: bearer(MAIN.integrationKey) };

	it('answers what a member set in the studio, read only', async () => {
		const set = await send(fake, '/__fake/workspace', {
			method: 'POST',
			json: {
				accentColor: '#1a73e8',
				proofWatermark: true,
				deliverOrdersTo: { id: 'adr_1', name: 'Warehouse' },
			},
		});
		expect(set.status).toBe(200);

		const read = await (await send(fake, '/v1/connect', asStore)).json();

		expect(read.branding).toEqual({
			logoUrl: null,
			accentColor: '#1a73e8',
			brandId: `br_${FIXTURE.tenant.live}`,
		});
		expect(read.proofWatermark).toBe(true);
		expect(read.deliverOrdersTo).toEqual({ id: 'adr_1', name: 'Warehouse' });
		expect(read.printing.minPrintDpi).toBe(150);
		expect(fake.violations).toEqual([]);
	});

	it('refuses a setting of the wrong type', async () => {
		const brand = await send(fake, '/__fake/workspace', {
			method: 'POST',
			json: { brandId: 'br_none' },
		});
		expect(brand.status).toBe(400);

		const refused = await send(fake, '/__fake/workspace', {
			method: 'POST',
			json: { proofWatermark: 'yes' },
		});

		expect(refused.status).toBe(400);
	});
});

describe('consent control', () => {
	it('sets how the next connect is answered', async () => {
		const response = await send(fake, '/__fake/consent', {
			method: 'POST',
			json: { environment: 'sandbox' },
		});

		expect(await response.json()).toEqual({
			decision: 'approve',
			environment: 'sandbox',
			member: FIXTURE.member.owner,
		});
		expect(fake.state.consent.environment).toBe('sandbox');
	});

	it('refuses an unknown decision and changes nothing', async () => {
		const response = await send(fake, '/__fake/consent', {
			method: 'POST',
			json: { decision: 'maybe' },
		});

		expect(response.status).toBe(400);
		expect(fake.state.consent.decision).toBe('approve');
	});

	it('is reset with the fixtures', () => {
		fake.state.consent.decision = 'deny';

		fake.reset('default', SITE_URL);

		expect(fake.state.consent).toEqual({
			decision: 'approve',
			environment: 'live',
			member: FIXTURE.member.owner,
		});
	});
});

describe('brands', () => {
	const asStore = { headers: bearer(MAIN.integrationKey) };
	const DEFAULT = `br_${FIXTURE.tenant.live}`;
	const PETS = 'br_pets';
	const ACCENTS = { default: '#1a73e8', pets: '#2e7d32' } as const;

	beforeEach(async () => {
		await send(fake, '/__fake/workspace', {
			method: 'POST',
			json: {
				accentColor: ACCENTS.default,
				brands: [{ id: PETS, name: 'Acme Pets', accentColor: ACCENTS.pets }],
			},
		});
	});

	it('lists the default brand first, and draws a store without one in it', async () => {
		const read = await (await send(fake, '/v1/connect', asStore)).json();

		expect(read.brandId).toBeNull();
		expect(read.branding).toEqual({
			brandId: DEFAULT,
			logoUrl: null,
			accentColor: ACCENTS.default,
		});
		expect(read.brands.map((brand: { id: string }) => brand.id)).toEqual([
			DEFAULT,
			PETS,
		]);
		expect(read.brands[0].isDefault).toBe(true);
		expect(fake.violations).toEqual([]);
	});

	it('draws the store in the brand it chose, and back in the default for null', async () => {
		const chosen = await send(fake, '/v1/connect', {
			...asStore,
			method: 'PATCH',
			json: { brandId: PETS },
		});
		const answer = await chosen.json();
		expect(chosen.status).toBe(200);
		expect(answer.brandId).toBe(PETS);
		expect(answer.branding.accentColor).toBe(ACCENTS.pets);

		const back = await send(fake, '/v1/connect', {
			...asStore,
			method: 'PATCH',
			json: { brandId: null },
		});
		expect((await back.json()).branding.brandId).toBe(DEFAULT);
		expect(fake.violations).toEqual([]);
	});

	it('refuses a brand the workspace does not keep, and changes nothing', async () => {
		const refused = await send(fake, '/v1/connect', {
			...asStore,
			method: 'PATCH',
			json: { brandId: 'br_gone', additionalOrigins: ['http://www.shop.test'] },
		});
		expect(refused.status).toBe(404);
		expect((await refused.json()).error).toBe('brand_not_found');

		const read = await (await send(fake, '/v1/connect', asStore)).json();
		expect(read.brandId).toBeNull();
		expect(read.allowedOrigins).toEqual([SITE_URL]);
	});

	it('answers a brand a member chose for the store in the studio', async () => {
		await send(fake, '/__fake/workspace', {
			method: 'POST',
			json: { brandId: PETS },
		});

		const read = await (await send(fake, '/v1/connect', asStore)).json();
		expect(read.branding.brandId).toBe(PETS);
	});
});
