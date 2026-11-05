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
import { createFake, VIOLATION_STATUS } from '../../fake-pixonaut/src/app';
import { FIXTURE } from '../../fake-pixonaut/src/fixture-ids';
import {
	MS_PER_DAY,
	PROOF_PAGE_DEFAULT_DAYS,
	PROOF_PAGE_MAX_DAYS,
} from '../../fake-pixonaut/src/handlers/share-links';
import { MESSAGE_MAX_LENGTH } from '../../fake-pixonaut/src/proof-pages';
import { bearer, MAIN, SITE_ORIGIN, SITE_URL, send } from './support';

/** A fixed instant for the fake's clock. */
const FROZEN_NOW = Date.parse('2026-10-15T12:00:00Z');

/** The fake's origin in `app.request`, as `support.ts` sends to it. */
const FAKE_ORIGIN = 'http://fake.test';

/** A revision past the first, so an event's revision is the design's own. */
const CORRECTED_REVISION = 3;

const asStore = { headers: bearer(MAIN.integrationKey) };
const asOtherStore = { headers: bearer(FIXTURE.store.other.integrationKey) };

/** The store's webhook endpoint on loopback, recording every event body. */
const received: { type: string; payload: unknown }[] = [];
let server: Server;
let endpointUrl: string;

beforeAll(async () => {
	server = createServer((request, response) => {
		const chunks: Buffer[] = [];
		request.on('data', (chunk: Buffer) => chunks.push(chunk));
		request.on('end', () => {
			received.push(JSON.parse(Buffer.concat(chunks).toString('utf8')));
			response.statusCode = 200;
			response.end();
		});
	});
	await new Promise<void>((resolve) => server.listen(0, '127.0.0.1', resolve));
	const { port } = server.address() as AddressInfo;
	endpointUrl = `http://127.0.0.1:${port}/wp-json/pixonaut/v1/webhook`;
});

afterAll(() => new Promise<void>((resolve) => server.close(() => resolve())));

let realNow = FROZEN_NOW;
const fake = createFake({ realNow: () => realNow });

beforeEach(async () => {
	realNow = FROZEN_NOW;
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

async function seed(json: Record<string, unknown> = {}): Promise<string> {
	const response = await send(fake, '/__fake/designs', {
		method: 'POST',
		json,
	});
	expect(response.status).toBe(200);
	return ((await response.json()) as { id: string }).id;
}

async function design(id: string) {
	return (await send(fake, `/__fake/designs/${id}`)).json();
}

function mint(json: Record<string, unknown>, as = asStore) {
	return send(fake, '/v1/share-links', { method: 'POST', json, ...as });
}

async function proofPage(
	subjectId: string,
	extra: Record<string, unknown> = {},
): Promise<{ token: string; url: string }> {
	const response = await mint({
		kind: 'proof-page',
		subjectId,
		mayDecide: true,
		...extra,
	});
	expect(response.status).toBe(201);
	return response.json();
}

/** Submits the page's form for `decision`, as a browser would. */
function submit(token: string, decision: string, revision: number) {
	return send(fake, `/h/proof/${token}/${decision}`, {
		method: 'POST',
		headers: { 'content-type': 'application/x-www-form-urlencoded' },
		body: `revision=${revision}`,
	});
}

describe('POST /v1/share-links', () => {
	it('mints a proof page on the fake that keeps its state and locale', async () => {
		const id = await seed();
		const returnUrl = `${SITE_URL}/account/designs?edit=1`;

		const response = await mint({
			kind: 'proof-page',
			subjectId: id,
			mayDecide: true,
			locale: 'sv',
			returnUrl,
			state: 'edit-7c1e',
		});

		expect(response.status).toBe(201);
		const body = await response.json();
		expect(body).toEqual({
			id: expect.any(String),
			token: expect.stringMatching(/^link_[0-9a-f]+$/),
			url: `${FAKE_ORIGIN}/h/proof/${body.token}?lang=sv`,
			locale: 'sv',
			kind: 'proof-page',
			subjectId: id,
			mayDecide: true,
			returnUrl,
			name: null,
			brandId: null,
			designLimit: null,
			printAreaFrame: false,
			placementFrame: false,
			createdAt: FROZEN_NOW,
			expiresAt: FROZEN_NOW + PROOF_PAGE_DEFAULT_DAYS * MS_PER_DAY,
		});
		expect(fake.state.shareLinks.get(body.token)).toMatchObject({
			storeId: MAIN.id,
			subject: { kind: 'design', id },
			proofPage: { state: 'edit-7c1e', locale: 'sv', returnUrl },
		});
	});

	it('takes any of the nine languages, and answers no lang without one', async () => {
		const german = await mint({
			kind: 'proof-page',
			subjectId: await seed(),
			locale: 'de',
		});
		const none = await mint({ kind: 'proof-page', subjectId: await seed() });

		expect(german.status).toBe(201);
		expect(await german.json()).toMatchObject({
			url: expect.stringMatching(/\?lang=de$/),
			locale: 'de',
		});
		const plain = await none.json();
		expect(plain.url).not.toContain('lang=');
		expect(plain.locale).toBeNull();
	});

	it('flags a locale outside the spec as a contract violation', async () => {
		const response = await mint({
			kind: 'proof-page',
			subjectId: await seed(),
			locale: 'EN',
		});

		expect(response.status).toBe(VIOLATION_STATUS);
		expect(fake.violations).not.toEqual([]);
		fake.violations.length = 0;
	});

	it('answers no return and no decision when the store names none', async () => {
		const response = await mint({
			kind: 'proof-page',
			subjectId: await seed(),
		});

		expect(await response.json()).toMatchObject({
			mayDecide: false,
			returnUrl: null,
		});
	});

	it('takes a lifetime up to 90 days and refuses a longer one', async () => {
		const id = await seed();

		const longest = await mint({
			kind: 'proof-page',
			subjectId: id,
			expiresInDays: PROOF_PAGE_MAX_DAYS,
		});
		const tooLong = await mint({
			kind: 'proof-page',
			subjectId: id,
			expiresInDays: PROOF_PAGE_MAX_DAYS + 1,
		});

		expect(longest.status).toBe(201);
		expect((await longest.json()).expiresAt).toBe(
			FROZEN_NOW + PROOF_PAGE_MAX_DAYS * MS_PER_DAY,
		);
		expect(tooLong.status).toBe(400);
		expect(await tooLong.json()).toEqual({
			error: 'expiry_too_long',
			kind: 'proof-page',
			maxDays: PROOF_PAGE_MAX_DAYS,
		});
	});

	it('refuses a design the store does not have', async () => {
		const id = await seed();

		const others = await mint(
			{ kind: 'proof-page', subjectId: id },
			asOtherStore,
		);
		const unknown = await mint({ kind: 'proof-page', subjectId: 'dsn_nope' });

		expect(others.status).toBe(404);
		expect(await others.json()).toEqual({ error: 'subject_not_found' });
		expect(unknown.status).toBe(404);
	});

	it.each([
		[
			'a locale on another kind',
			{ kind: 'order-status', locale: 'sv' },
			'locale_not_allowed',
		],
		[
			'a return on another kind',
			{ kind: 'order-status', returnUrl: `${SITE_URL}/back` },
			'return_url_not_allowed',
		],
		[
			'a decision on another kind',
			{ kind: 'designer', mayDecide: true },
			'decide_not_allowed',
		],
		[
			'a state without a return',
			{ kind: 'proof-page', state: 'orphan' },
			'invalid_input',
		],
		[
			'a return off the store',
			{ kind: 'proof-page', returnUrl: 'http://elsewhere.test/back' },
			'return_url_off_store',
		],
		[
			'a return that is not a web address',
			{ kind: 'proof-page', returnUrl: 'javascript:alert(1)' },
			'invalid_input',
		],
	])('refuses %s', async (_, json, error) => {
		const response = await mint({ subjectId: await seed(), ...json });

		expect(response.status).toBe(400);
		expect(await response.json()).toEqual(
			error === 'invalid_input'
				? { error, reason: expect.any(String) }
				: { error },
		);
	});

	it('takes a plain http return on the store, as a non-production Pixonaut does', async () => {
		const response = await mint({
			kind: 'proof-page',
			subjectId: await seed(),
			returnUrl: `${SITE_ORIGIN}/cart`,
		});

		expect(response.status).toBe(201);
	});

	it('refuses every kind but a proof page to a store', async () => {
		const response = await mint({ kind: 'designer', subjectId: 'mini-tee' });

		expect(response.status).toBe(403);
		expect(await response.json()).toEqual({ error: 'forbidden' });
	});
});

describe('the hosted proof page', () => {
	it('shows the proof with an Approve and a Reject button', async () => {
		const { token } = await proofPage(await seed(), { locale: 'sv' });

		const response = await send(fake, `/h/proof/${token}`);

		expect(response.status).toBe(200);
		const page = await response.text();
		expect(page).toContain('lang="sv"');
		expect(page).toContain('<button type="submit">Approve</button>');
		expect(page).toContain('<button type="submit">Reject</button>');
		expect(page).toContain(`src="/h/proof/${token}/image"`);
		const image = await send(fake, `/h/proof/${token}/image`);
		expect(image.headers.get('content-type')).toBe('image/jpeg');
	});

	it('is shown in its ?lang=, else its locale, else the workspace default', async () => {
		const tenant = fake.state.tenants.get(FIXTURE.tenant.live);
		if (tenant === undefined) {
			throw new Error('The fixture has no live tenant.');
		}
		tenant.workspace.defaultLanguage = 'fi';
		const { token: plain } = await proofPage(await seed());
		const { token: swedish } = await proofPage(await seed(), { locale: 'sv' });

		const lang = async (path: string) =>
			(await (await send(fake, path)).text()).match(/lang="([a-z]+)"/)?.[1];

		expect(await lang(`/h/proof/${plain}`)).toBe('fi');
		expect(await lang(`/h/proof/${swedish}`)).toBe('sv');
		expect(await lang(`/h/proof/${swedish}?lang=da`)).toBe('da');
		expect(await lang(`/h/proof/${swedish}?lang=xx`)).toBe('sv');
	});

	it('approves at the revision shown and delivers design.approved once', async () => {
		const id = await seed({ revision: CORRECTED_REVISION });
		const { token } = await proofPage(id);

		const response = await submit(token, 'approve', CORRECTED_REVISION);
		const again = await submit(token, 'approve', CORRECTED_REVISION);

		expect(response.status).toBe(200);
		expect(await response.text()).toContain('Design approved');
		expect(again.status).toBe(200);
		const consent = { kind: 'proof_page', at: FROZEN_NOW, ref: null };
		expect(await design(id)).toMatchObject({
			status: 'approved',
			approvedRevision: CORRECTED_REVISION,
			consent,
		});
		expect(received).toEqual([
			expect.objectContaining({
				type: 'design.approved',
				payload: {
					designId: id,
					approvedVia: 'explicit',
					consent,
					revision: CORRECTED_REVISION,
				},
			}),
		]);
	});

	it('rejects and delivers design.rejected', async () => {
		const id = await seed({ revision: CORRECTED_REVISION });
		const { token } = await proofPage(id);

		const response = await submit(token, 'reject', CORRECTED_REVISION);

		expect(response.status).toBe(200);
		expect(await response.text()).toContain('Design rejected');
		expect(await design(id)).toMatchObject({ status: 'rejected' });
		expect(received).toEqual([
			expect.objectContaining({
				type: 'design.rejected',
				payload: { designId: id, revision: CORRECTED_REVISION, reason: null },
			}),
		]);
	});

	it('refuses a decision about a revision the design has moved past', async () => {
		const id = await seed({ revision: CORRECTED_REVISION });
		const { token } = await proofPage(id);

		const response = await submit(token, 'approve', 1);

		expect(response.status).toBe(409);
		expect(received).toEqual([]);
	});

	it('refuses an expired link and an unknown one', async () => {
		const { token } = await proofPage(await seed());
		realNow += PROOF_PAGE_DEFAULT_DAYS * MS_PER_DAY;

		expect((await send(fake, `/h/proof/${token}`)).status).toBe(410);
		expect((await submit(token, 'approve', 1)).status).toBe(410);
		expect((await send(fake, '/h/proof/link_nope')).status).toBe(404);
		expect(received).toEqual([]);
	});

	it('shows a link that may not decide without buttons and refuses its decision', async () => {
		const response = await mint({
			kind: 'proof-page',
			subjectId: await seed(),
		});
		const { token } = await response.json();

		const page = await (await send(fake, `/h/proof/${token}`)).text();

		expect(page).not.toContain('<button');
		expect((await submit(token, 'approve', 1)).status).toBe(403);
	});
});

describe('POST /__fake/proof-pages/:token/approve|reject', () => {
	function decide(token: string, decision: string, json = {}) {
		return send(fake, `/__fake/proof-pages/${token}/${decision}`, {
			method: 'POST',
			json,
		});
	}

	it('decides as the page does, and answers what it delivered', async () => {
		const id = await seed();
		const { token } = await proofPage(id);

		const response = await decide(token, 'approve');
		const again = await decide(token, 'approve', { revision: 1 });

		expect(response.status).toBe(200);
		expect(await response.json()).toMatchObject({
			designId: id,
			status: 'approved',
			revision: 1,
			emitted: true,
			attempts: [{ type: 'design.approved', status: 200 }],
		});
		expect(await again.json()).toMatchObject({ emitted: false, attempts: [] });
		expect(received).toHaveLength(1);
	});

	it('refuses a decision the design no longer takes', async () => {
		const { token } = await proofPage(await seed({ status: 'approved' }));

		const response = await decide(token, 'reject');

		expect(response.status).toBe(409);
		expect(await response.json()).toEqual({ error: 'not_committed' });
	});
});

describe('asking for changes on the hosted proof page', () => {
	const MESSAGE = 'Could the name be larger?';
	/** One character past what `request-changes` takes as a message. */
	const TOO_LONG_MESSAGE = 'x'.repeat(MESSAGE_MAX_LENGTH + 1);

	/** Submits the page's "Request changes" form, as a browser would. */
	function ask(token: string, message: string, revision: number) {
		return send(fake, `/h/proof/${token}/request-changes`, {
			method: 'POST',
			headers: { 'content-type': 'application/x-www-form-urlencoded' },
			body: new URLSearchParams({
				message,
				revision: String(revision),
			}).toString(),
		});
	}

	function control(token: string, json: Record<string, unknown>) {
		return send(fake, `/__fake/proof-pages/${token}/request-changes`, {
			method: 'POST',
			json,
		});
	}

	it('shows a "What should change?" form with a Request changes button', async () => {
		const { token } = await proofPage(await seed());

		const page = await (await send(fake, `/h/proof/${token}`)).text();

		expect(page).toContain(`action="/h/proof/${token}/request-changes"`);
		expect(page).toContain('<label for="message">What should change?</label>');
		expect(page).toMatch(/<textarea id="message" name="message" required/);
		expect(page).toContain('<button type="submit">Request changes</button>');
	});

	it('delivers design.changes_requested and keeps the design committed', async () => {
		const id = await seed({ revision: CORRECTED_REVISION });
		const { token } = await proofPage(id);

		const response = await ask(token, MESSAGE, CORRECTED_REVISION);

		expect(response.status).toBe(200);
		const page = await response.text();
		expect(page).toContain('Request sent');
		expect(page).toContain('Your request was sent to the store.');
		expect(await design(id)).toMatchObject({
			status: 'committed',
			revision: CORRECTED_REVISION,
		});
		expect(received).toEqual([
			expect.objectContaining({
				type: 'design.changes_requested',
				payload: {
					designId: id,
					revision: CORRECTED_REVISION,
					message: MESSAGE,
				},
			}),
		]);
	});

	it.each([
		[
			'adds the state to the store’s own query',
			'/account/designs?edit=1',
			'edit-7c1e',
			'/account/designs?edit=1&state=edit-7c1e',
		],
		[
			'replaces a state already there and drops a proof token',
			'/cart?state=old&proofToken=tok&proofToken%5Bfront%5D=f&keep=a%20b',
			'new state',
			'/cart?keep=a%20b&state=new%20state',
		],
		[
			'drops a state already there when the link carries none',
			'/cart?state=old&keep=1',
			undefined,
			'/cart?keep=1',
		],
		['sends no query when there is none to send', '/cart', undefined, '/cart'],
	])('%s', async (_, path, state, expected) => {
		const { token } = await proofPage(await seed(), {
			returnUrl: `${SITE_URL}${path}`,
			...(state === undefined ? {} : { state }),
		});

		const response = await ask(token, MESSAGE, 1);

		expect(response.status).toBe(303);
		expect(response.headers.get('location')).toBe(`${SITE_URL}${expected}`);
		expect(received).toHaveLength(1);
	});

	it('refuses a link that may not decide', async () => {
		const response = await mint({
			kind: 'proof-page',
			subjectId: await seed(),
		});
		const { token } = await response.json();

		expect((await ask(token, MESSAGE, 1)).status).toBe(403);
		expect(received).toEqual([]);
	});

	it('refuses a request about a revision the design has moved past', async () => {
		const { token } = await proofPage(
			await seed({ revision: CORRECTED_REVISION }),
		);

		const page = await ask(token, MESSAGE, 1);
		const controlled = await control(token, { message: MESSAGE, revision: 1 });

		expect(page.status).toBe(409);
		expect(controlled.status).toBe(409);
		expect(await controlled.json()).toEqual({
			error: 'revision_changed',
			revision: CORRECTED_REVISION,
		});
		expect(received).toEqual([]);
	});

	it.each(['approved', 'rejected'])(
		'refuses a design already %s',
		async (status) => {
			const { token } = await proofPage(await seed({ status }));

			const response = await control(token, { message: MESSAGE });

			expect(response.status).toBe(409);
			expect(await response.json()).toEqual({ error: 'not_committed' });
			expect(received).toEqual([]);
		},
	);

	it.each([
		['an empty message', ''],
		['a message too long', TOO_LONG_MESSAGE],
	])('refuses %s', async (_, message) => {
		const { token } = await proofPage(await seed());

		const page = await ask(token, message, 1);
		const controlled = await control(token, { message });
		const missing = await control(token, {});

		expect(page.status).toBe(400);
		expect(controlled.status).toBe(400);
		expect(await controlled.json()).toEqual({ error: 'bad_request' });
		expect(missing.status).toBe(400);
		expect(received).toEqual([]);
	});

	it('takes a request through the control API and answers where the page sends the customer', async () => {
		const id = await seed();
		const { token } = await proofPage(id, {
			returnUrl: `${SITE_URL}/account/designs?edit=1`,
			state: 'edit-7c1e',
		});

		const response = await control(token, { message: MESSAGE, revision: 1 });

		expect(response.status).toBe(200);
		expect(await response.json()).toEqual({
			designId: id,
			revision: 1,
			emitted: true,
			attempts: [
				expect.objectContaining({
					type: 'design.changes_requested',
					status: 200,
				}),
			],
			redirect: `${SITE_URL}/account/designs?edit=1&state=edit-7c1e`,
		});
	});

	it('answers no redirect when the link names no return', async () => {
		const { token } = await proofPage(await seed());

		const response = await control(token, { message: MESSAGE });

		expect(await response.json()).toMatchObject({ redirect: null });
	});
});
