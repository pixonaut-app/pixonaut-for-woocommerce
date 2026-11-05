import { readFileSync } from 'node:fs';
import { join } from 'node:path';
import { afterEach, beforeEach, describe, expect, it } from 'vitest';
import { createFake } from '../../fake-pixonaut/src/app';
import { FIXTURE } from '../../fake-pixonaut/src/fixture-ids';
import { EVENTS_LOOKED_THROUGH } from '../../fake-pixonaut/src/handlers/events';
import { CONTRACTS_DIR } from '../../fake-pixonaut/src/spec';
import { bearer, MAIN, SITE_URL, send } from './support';

const fake = createFake();
const asStore = { headers: bearer(MAIN.integrationKey) };

/** A loopback address nothing listens on: a delivery to it fails fast. */
const UNREACHABLE_ENDPOINT = 'http://127.0.0.1:1/unreachable';

/** The reference's first example body for a webhook. */
function example(type: string): { type: string; payload: unknown } {
	const spec = JSON.parse(
		readFileSync(join(CONTRACTS_DIR, 'openapi.json'), 'utf8'),
	);
	const examples =
		spec.webhooks[type].post.requestBody.content['application/json'].examples;
	const { value } = Object.values(examples)[0] as {
		value: { type: string; payload: unknown };
	};
	return { type: value.type, payload: value.payload };
}

const APPROVED = example('design.approved');
const SUBMITTED = example('order.submitted');

type Envelope = { id: string; type: string; payload: unknown };

function control(path: string, json: unknown) {
	return send(fake, `/__fake/${path}`, { method: 'POST', json });
}

/** Emits `count` events of `event`'s type, ids `<prefix>_0` onward. */
async function emitMany(
	count: number,
	event = APPROVED,
	prefix = 'evt',
	store: string = MAIN.id,
): Promise<string[]> {
	const ids = Array.from({ length: count }, (_, n) => `${prefix}_${n}`);
	const response = await control('webhooks/emit', {
		store,
		events: ids.map((id) => ({ ...event, id })),
	});
	expect(response.status, await response.clone().text()).toBe(200);
	return ids;
}

async function pull(
	query: Record<string, string> = {},
	key: string = MAIN.integrationKey,
): Promise<{ events: Envelope[]; nextCursor: string | null }> {
	const response = await send(
		fake,
		`/v1/events?${new URLSearchParams(query)}`,
		{ headers: bearer(key) },
	);
	expect(response.status, await response.clone().text()).toBe(200);
	return response.json();
}

beforeEach(async () => {
	fake.reset('default', SITE_URL);
	// The stream is the subject here, not the deliveries: none is sent.
	for (const store of Object.values(FIXTURE.store)) {
		await control('webhooks/drop', { store: store.id, drop: true });
	}
});

afterEach(() => {
	expect(fake.violations).toEqual([]);
});

describe('GET /v1/events', () => {
	it('answers every emitted event in order, in its envelope', async () => {
		const ids = await emitMany(3);

		const page = await pull();

		expect(page.events.map((e) => e.id)).toEqual(ids);
		expect(page.events[0]).toMatchObject({
			type: 'design.approved',
			payload: APPROVED.payload,
			createdAt: expect.any(Number),
		});
		expect(page.nextCursor).toBeNull();
	});

	it('records an event whether or not its delivery got through', async () => {
		await control('webhooks/drop', { drop: false });
		await control('webhooks/endpoint', { url: UNREACHABLE_ENDPOINT });

		const ids = await emitMany(1);

		expect((await pull()).events.map((e) => e.id)).toEqual(ids);
	});

	it('queues no delivery for a store it cannot reach, whose events are pulled', async () => {
		await control('webhooks/drop', { drop: false });
		await control('webhooks/endpoint', { reachable: false });

		const ids = await emitMany(1);

		expect((await pull()).events.map((e) => e.id)).toEqual(ids);
		const seen = await send(fake, '/__fake/webhooks/deliveries');
		const { deliveries } = (await seen.json()) as { deliveries: unknown[] };
		expect(deliveries).toEqual([]);
	});

	it('records a move-order step like any other event', async () => {
		await control('orders/move', { orderId: 'ord_1', printId: 'prt_1' });

		expect((await pull()).events.map((e) => e.type)).toEqual([
			'order.status_changed',
			'order.status_changed',
			'order.status_changed',
		]);
	});

	it('pages by `limit` and `after` until `nextCursor` is null', async () => {
		const ids = await emitMany(5);

		const first = await pull({ limit: '2' });
		const second = await pull({ limit: '2', after: first.nextCursor ?? '' });
		const last = await pull({ limit: '2', after: second.nextCursor ?? '' });

		expect(first).toEqual({
			events: [expect.objectContaining({ id: ids[0] }), expect.anything()],
			nextCursor: ids[1],
		});
		expect(second.events.map((e) => e.id)).toEqual(ids.slice(2, 4));
		expect(second.nextCursor).toBe(ids[3]);
		expect(last.events.map((e) => e.id)).toEqual([ids[4]]);
		expect(last.nextCursor).toBeNull();
	});

	it('answers null when a page ends on the last event', async () => {
		await emitMany(2);

		expect((await pull({ limit: '2' })).nextCursor).toBeNull();
	});

	it('answers nothing after the last event, until more is recorded', async () => {
		const ids = await emitMany(2);
		const last = ids[1] ?? '';

		expect(await pull({ after: last })).toEqual({
			events: [],
			nextCursor: null,
		});

		await emitMany(1, APPROVED, 'later');

		expect((await pull({ after: last })).events.map((e) => e.id)).toEqual([
			'later_0',
		]);
	});

	it('narrows to `types`', async () => {
		await emitMany(2);
		const submitted = await emitMany(2, SUBMITTED, 'sub');

		const page = await pull({ types: 'order.submitted,design.edited' });

		expect(page.events.map((e) => e.id)).toEqual(submitted);
		expect(page.nextCursor).toBeNull();
	});

	it(`looks through at most ${EVENTS_LOOKED_THROUGH} events, moving the cursor on`, async () => {
		const approved = await emitMany(EVENTS_LOOKED_THROUGH);
		const submitted = await emitMany(1, SUBMITTED, 'sub');

		const first = await pull({ types: 'order.submitted' });
		const second = await pull({
			types: 'order.submitted',
			after: first.nextCursor ?? '',
		});

		expect(first).toEqual({ events: [], nextCursor: approved.at(-1) });
		expect(second.events.map((e) => e.id)).toEqual(submitted);
		expect(second.nextCursor).toBeNull();
	});

	it('refuses an `after` that is no event of the store', async () => {
		await emitMany(1, APPROVED, 'other', FIXTURE.store.other.id);

		const response = await send(fake, '/v1/events?after=other_0', asStore);

		expect(response.status).toBe(400);
		expect(await response.json()).toEqual({ error: 'unknown_event' });
	});

	it('refuses a type that is no event type', async () => {
		for (const type of ['webhook.ping', 'nope']) {
			const response = await send(
				fake,
				`/v1/events?types=design.approved,${type}`,
				asStore,
			);

			expect(response.status).toBe(400);
			expect(await response.json()).toEqual({
				error: 'invalid_event_type',
				type,
			});
		}
	});

	it("never answers another store's events", async () => {
		await emitMany(2, APPROVED, 'other', FIXTURE.store.other.id);
		const own = await emitMany(1);

		expect((await pull()).events.map((e) => e.id)).toEqual(own);
		expect(
			(await pull({}, FIXTURE.store.other.integrationKey)).events.map(
				(e) => e.id,
			),
		).toEqual(['other_0', 'other_1']);
	});

	it('forgets every event on reset', async () => {
		await emitMany(1);

		await control('reset', {});

		expect((await pull()).events).toEqual([]);
	});
});

describe('design changes', () => {
	async function seeded(json: Record<string, unknown> = {}): Promise<string> {
		const response = await control('designs', json);
		expect(response.status).toBe(200);
		return (await response.json()).id;
	}

	function readDesign(id: string) {
		return send(fake, `/v1/designs/${id}`, asStore);
	}

	it('edits a design to its next revision, clearing its approval', async () => {
		await control('webhooks/drop', { drop: false });
		await control('webhooks/endpoint', { url: UNREACHABLE_ENDPOINT });
		const id = await seeded({ status: 'approved', revision: 2 });

		const response = await control('designs/edit', { design: id });

		expect(response.status).toBe(200);
		const { attempts } = await response.json();
		expect(attempts).toMatchObject([{ type: 'design.edited' }]);
		expect(JSON.parse(attempts[0].body).payload).toEqual({
			designId: id,
			revision: 3,
		});
		expect(await (await readDesign(id)).json()).toMatchObject({
			revision: 3,
			status: 'committed',
		});
		expect((await pull()).events).toMatchObject([
			{ type: 'design.edited', payload: { designId: id, revision: 3 } },
		]);
	});

	it('refuses to edit a rejected design', async () => {
		const id = await seeded({ status: 'rejected' });

		expect((await control('designs/edit', { design: id })).status).toBe(400);
		expect((await pull()).events).toEqual([]);
	});

	it('deletes a design', async () => {
		const id = await seeded({ status: 'approved' });

		const response = await control('designs/delete', { design: id });

		expect(response.status).toBe(200);
		expect((await readDesign(id)).status).toBe(404);
		expect((await pull()).events).toMatchObject([
			{ type: 'design.deleted', payload: { designId: id } },
		]);
	});

	it("erases a design's personal data", async () => {
		const id = await seeded();

		const response = await control('designs/erase', { design: id });

		expect(response.status).toBe(200);
		expect(await (await readDesign(id)).json()).toMatchObject({
			personalDataErasedAt: expect.any(Number),
			editable: false,
		});
		expect((await pull()).events).toMatchObject([
			{ type: 'design.personal_data_erased', payload: { designId: id } },
		]);
		expect((await control('designs/edit', { design: id })).status).toBe(400);
	});

	it("refuses a design that is not the store's", async () => {
		const other = await seeded({ store: FIXTURE.store.other.id });

		for (const change of ['edit', 'delete', 'erase']) {
			for (const body of [{}, { design: 'dsn_nope' }, { design: other }]) {
				expect((await control(`designs/${change}`, body)).status).toBe(400);
			}
		}
		const kept = await send(fake, `/v1/designs/${other}`, {
			headers: bearer(FIXTURE.store.other.integrationKey),
		});
		expect(await kept.json()).toMatchObject({
			revision: 1,
			personalDataErasedAt: null,
		});
		expect((await pull({}, FIXTURE.store.other.integrationKey)).events).toEqual(
			[],
		);
	});

	it("changes another store's design when it is named", async () => {
		const id = await seeded({ store: FIXTURE.store.other.id });

		const response = await control('designs/delete', {
			store: FIXTURE.store.other.id,
			design: id,
		});

		expect(response.status).toBe(200);
		expect(
			(await pull({}, FIXTURE.store.other.integrationKey)).events,
		).toMatchObject([{ type: 'design.deleted' }]);
	});
});
