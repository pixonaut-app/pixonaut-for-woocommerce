import { beforeEach, describe, expect, it } from 'vitest';
import { createFake } from '../../fake-pixonaut/src/app';
import { FIXTURE } from '../../fake-pixonaut/src/fixture-ids';
import { bearer, MAIN, SITE_URL, seedPrint, send } from './support';

const fake = createFake();
const asStore = bearer(MAIN.integrationKey);
const DESIGN = 'dsn_1';
const shipping = {
	recipient: { name: 'Ada Lovelace' },
	shippingAddress: { line1: 'Storgatan 1', city: 'Lund', country: 'SE' },
};

function post(path: string, json?: unknown, key?: string) {
	return send(fake, path, {
		method: 'POST',
		json,
		headers:
			key === undefined ? asStore : { ...asStore, 'idempotency-key': key },
	});
}

function eraseDesign(id: string) {
	return post(`/v1/designs/${id}/erase-personal-data`);
}

function eraseOrder(id: string) {
	return post(`/v1/orders/${id}/erase-personal-data`);
}

/** Prints the approved design `DESIGN` on the locked blank's front, seeded
 *  unordered. */
async function printed(): Promise<string> {
	const seeded = await send(fake, '/__fake/designs', {
		method: 'POST',
		json: {
			id: DESIGN,
			status: 'approved',
			blankId: FIXTURE.blank.tee,
			placementId: FIXTURE.placement.front,
		},
	});
	expect(seeded.status).toBe(200);
	return seedPrint(fake, DESIGN);
}

/** Orders `printId`; answers the order's id. */
async function ordered(printId: string, key = 'o1'): Promise<string> {
	const response = await post(
		'/v1/orders',
		{
			prints: [{ printId }],
			externalRef: 'wc-42',
			variant: FIXTURE.variant.teeWhiteS,
			...shipping,
		},
		key,
	);
	expect(response.status).toBe(201);
	return (await response.json()).id;
}

async function events(): Promise<unknown[]> {
	const response = await send(fake, '/v1/events', { headers: asStore });
	expect(response.status).toBe(200);
	return (await response.json()).events;
}

beforeEach(() => {
	fake.reset('default', SITE_URL);
});

describe('POST /v1/designs/{id}/erase-personal-data', () => {
	it('erases a design once, emitting design.personal_data_erased', async () => {
		await printed();

		const first = await eraseDesign(DESIGN);
		const again = await eraseDesign(DESIGN);

		expect(first.status).toBe(200);
		const erased = await first.json();
		expect(erased).toMatchObject({
			id: DESIGN,
			personalDataErasedAt: expect.any(Number),
		});
		expect((await again.json()).personalDataErasedAt).toBe(
			erased.personalDataErasedAt,
		);
		expect(await events()).toMatchObject([
			{ type: 'design.personal_data_erased', payload: { designId: DESIGN } },
		]);
		expect(fake.violations).toEqual([]);
	});

	it("answers an erased design's proof proof_not_found, as its file is deleted", async () => {
		await printed();
		const proof = () =>
			send(fake, `/v1/designs/${DESIGN}/proof`, { headers: asStore });
		expect((await proof()).status).toBe(200);

		await eraseDesign(DESIGN);

		const gone = await proof();
		expect(gone.status).toBe(404);
		expect(await gone.json()).toEqual({ error: 'proof_not_found' });
		expect(fake.violations).toEqual([]);
	});

	it('refuses to order an erased design, by itself or its print, or retry it', async () => {
		const printId = await printed();
		await send(fake, '/__fake/orders/fail', {
			method: 'POST',
			json: { times: 1 },
		});
		const failed = await ordered(printId);
		await eraseDesign(DESIGN);

		const reprint = await post(
			'/v1/orders',
			{
				designs: [{ designId: DESIGN }],
				variant: FIXTURE.variant.teeWhiteS,
				...shipping,
			},
			'o3',
		);
		const order = await post(
			'/v1/orders',
			{ prints: [{ printId }], ...shipping },
			'o2',
		);
		const retry = await post(`/v1/orders/${failed}/retry`, {});

		for (const response of [reprint, order, retry]) {
			expect(response.status).toBe(409);
			expect(await response.json()).toEqual({ error: 'design_erased' });
		}
		expect(fake.violations).toEqual([]);
	});

	it('answers a deleted design 204 and an unknown one not_found', async () => {
		const seeded = await send(fake, '/__fake/designs', {
			method: 'POST',
			json: { id: DESIGN },
		});
		expect(seeded.status).toBe(200);
		expect(
			(
				await send(fake, `/v1/designs/${DESIGN}`, {
					method: 'DELETE',
					headers: asStore,
				})
			).status,
		).toBe(204);

		expect((await eraseDesign(DESIGN)).status).toBe(204);
		expect((await eraseDesign('dsn_unknown')).status).toBe(404);
		expect(fake.violations).toEqual([]);
	});
});

describe('POST /v1/orders/{id}/erase-personal-data', () => {
	it('refuses an order on its way, then erases it once cancelled', async () => {
		const id = await ordered(await printed());

		const early = await eraseOrder(id);
		await post(`/v1/orders/${id}/cancel`);
		const erased = await eraseOrder(id);

		expect(early.status).toBe(409);
		expect(await early.json()).toEqual({ error: 'order_in_flight' });
		expect(erased.status).toBe(200);
		expect(await erased.json()).toMatchObject({
			id,
			recipient: null,
			shippingAddress: null,
			personalDataErasedAt: expect.any(Number),
		});
		expect(fake.violations).toEqual([]);
	});

	it('answers an unknown order order_not_found', async () => {
		const response = await eraseOrder('ord_unknown');

		expect(response.status).toBe(404);
		expect(await response.json()).toEqual({ error: 'order_not_found' });
	});
});
