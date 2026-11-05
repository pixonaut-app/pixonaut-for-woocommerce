import { beforeEach, describe, expect, it } from 'vitest';
import { createFake } from '../../fake-pixonaut/src/app';
import { FIXTURE } from '../../fake-pixonaut/src/fixture-ids';
import { PROOF_LIFETIME_MS } from '../../fake-pixonaut/src/proofs';
import { bearer, MAIN, SITE_URL, send } from './support';

const fake = createFake();
const asStore = { headers: bearer(MAIN.integrationKey) };
const asOtherStore = { headers: bearer(FIXTURE.store.other.integrationKey) };

/** mini-tee's 280:360 ratio fitted into the fixture placement's 300×360 default box. */
const FITTED_DEFAULT = { widthMm: 280, heightMm: 360 };
/** When the customer ticked the box, in epoch milliseconds. */
const CONSENTED_AT = Date.parse('2026-10-14T12:00:00Z');
const CONSENT = { kind: 'checkbox', at: CONSENTED_AT };

function seed(json: Record<string, unknown> = {}) {
	return send(fake, '/__fake/designs', { method: 'POST', json });
}

function approve(
	id: string,
	json: Record<string, unknown> = { consent: CONSENT },
) {
	return send(fake, `/v1/designs/${id}/approve`, {
		method: 'POST',
		json,
		...asStore,
	});
}

beforeEach(() => {
	fake.reset('default', SITE_URL);
});

async function mintProof(json: Record<string, unknown> = {}) {
	const response = await send(fake, '/__fake/proofs', {
		method: 'POST',
		json,
	});
	expect(response.status).toBe(200);
	return (await response.json()) as {
		proofToken: string;
		proofId: string;
		printSize: unknown;
	};
}

async function commit(
	proofToken: string,
	key = `commit:${proofToken}`,
	extra: Record<string, unknown> = {},
) {
	return send(fake, '/v1/designs', {
		method: 'POST',
		headers: { ...asStore.headers, 'idempotency-key': key },
		json: { proofToken, externalRef: 'wc:site:cart:1', ...extra },
	});
}

async function committed(json: Record<string, unknown> = {}): Promise<string> {
	const { proofToken } = await mintProof(json);
	const response = await commit(proofToken);
	expect(response.status).toBe(201);
	return ((await response.json()) as { designId: string }).designId;
}

describe('POST /__fake/proofs', () => {
	it('mints a proof of the live template on the front, at the fitted default size', async () => {
		const proof = await mintProof();

		expect(proof.proofToken).toMatch(/^prf\./);
		expect(proof.printSize).toEqual(FITTED_DEFAULT);
	});

	it('mints a proof previewed on the variant and view a test names', async () => {
		const preview = {
			variant: FIXTURE.variant.teeWhiteM,
			viewId: 'view_front',
		};
		const designId = await committed(preview);

		const response = await send(fake, `/v1/designs/${designId}`, asStore);

		expect(await response.json()).toMatchObject({
			proofVariant: preview.variant,
			proofViewId: preview.viewId,
		});
		expect(fake.violations).toEqual([]);
	});

	it('refuses a placement the blank does not have', async () => {
		const response = await send(fake, '/__fake/proofs', {
			method: 'POST',
			json: { placementId: 'sleeve' },
		});

		expect(response.status).toBe(400);
	});
});

describe('POST /v1/designs', () => {
	it('commits a proof token', async () => {
		const { proofToken } = await mintProof();

		const response = await commit(proofToken);

		expect(response.status).toBe(201);
		expect(await response.json()).toMatchObject({
			printFloorDpi: 150,
			lowResolution: [],
		});
		expect(fake.violations).toEqual([]);
	});

	it('keeps what the commit said of the preview, and null where it said nothing', async () => {
		for (const previewOpened of [true, false]) {
			const { proofToken } = await mintProof();
			const { designId } = (await (
				await commit(proofToken, undefined, { previewOpened })
			).json()) as { designId: string };
			const read = await send(fake, `/v1/designs/${designId}`, asStore);
			expect(await read.json()).toMatchObject({ previewOpened });
		}
		const designId = await committed();
		const read = await send(fake, `/v1/designs/${designId}`, asStore);
		expect(await read.json()).toMatchObject({ previewOpened: null });
		expect(fake.violations).toEqual([]);
	});

	it('keeps the fulfilment keys the commit carried', async () => {
		const { proofToken } = await mintProof();

		const { designId } = (await (
			await commit(proofToken, undefined, {
				fulfilmentPayload: { placement: 'front' },
			})
		).json()) as { designId: string };

		const read = await send(fake, `/v1/designs/${designId}`, asStore);
		expect(await read.json()).toMatchObject({
			fulfilmentPayload: { placement: 'front' },
		});
		expect(fake.violations).toEqual([]);
	});

	it('replays a repeat with the same key', async () => {
		const { proofToken } = await mintProof();
		const first = await (await commit(proofToken)).json();

		const again = await commit(proofToken);

		expect(again.headers.get('Idempotent-Replayed')).toBe('true');
		expect(await again.json()).toEqual(first);
	});

	it('answers a proof committed under another key with its design', async () => {
		const { proofToken } = await mintProof();
		const { designId } = await (await commit(proofToken)).json();

		const response = await commit(proofToken, 'another-key');

		expect(response.status).toBe(409);
		expect(await response.json()).toEqual({
			error: 'proof_already_committed',
			designId,
		});
	});

	it('refuses an expired proof and an unknown one', async () => {
		const { proofToken } = await mintProof();
		fake.clock.advance(PROOF_LIFETIME_MS);

		expect((await commit(proofToken)).status).toBe(410);
		expect((await commit('prf.unknown')).status).toBe(404);
		expect(fake.violations).toEqual([]);
	});
});

describe('GET /v1/designs/{id}', () => {
	it('reports the template, blank, placement and size the proof named', async () => {
		const designId = await committed({
			templateId: FIXTURE.template.previous,
			printSize: { widthMm: 250, heightMm: 300 },
		});

		const response = await send(fake, `/v1/designs/${designId}`, asStore);

		expect(response.status).toBe(200);
		expect(await response.json()).toMatchObject({
			id: designId,
			status: 'committed',
			templateBaseId: 'mini-tee',
			templateVersion: 1,
			blankId: FIXTURE.blank.tee,
			placementId: FIXTURE.placement.front,
			printSize: { widthMm: 250, heightMm: 300 },
			externalRef: 'wc:site:cart:1',
			revision: 1,
		});
		expect(fake.violations).toEqual([]);
	});

	it('reports a tampered proof without a size as null', async () => {
		const designId = await committed({ printSize: null });

		const response = await send(fake, `/v1/designs/${designId}`, asStore);

		expect((await response.json()).printSize).toBeNull();
	});

	it("does not show another store's design", async () => {
		const designId = await committed();

		const response = await send(fake, `/v1/designs/${designId}`, asOtherStore);

		expect(response.status).toBe(404);
	});
});

describe('PUT /v1/designs/{id}/external-ref', () => {
	it("replaces the store's reference", async () => {
		const designId = await committed();

		const response = await send(fake, `/v1/designs/${designId}/external-ref`, {
			method: 'PUT',
			headers: asStore.headers,
			json: { externalRef: 'wc:site:order:7:item:9' },
		});

		expect(await response.json()).toEqual({
			designId,
			externalRef: 'wc:site:order:7:item:9',
		});
		const read = await send(fake, `/v1/designs/${designId}`, asStore);
		expect((await read.json()).externalRef).toBe('wc:site:order:7:item:9');
	});
});

describe('POST /v1/designs/{id}/duplicate', () => {
	it('makes a committed copy with no reference and leaves the source as it was', async () => {
		const designId = await committed();
		await send(fake, `/v1/designs/${designId}/external-ref`, {
			method: 'PUT',
			headers: asStore.headers,
			json: { externalRef: 'wc:site:order:7:item:9' },
		});

		const response = await send(fake, `/v1/designs/${designId}/duplicate`, {
			method: 'POST',
			...asStore,
		});

		expect(response.status).toBe(201);
		const { designId: copyId } = (await response.json()) as {
			designId: string;
		};
		expect(copyId).not.toBe(designId);
		const copy = await (
			await send(fake, `/v1/designs/${copyId}`, asStore)
		).json();
		expect(copy).toMatchObject({
			status: 'committed',
			revision: 1,
			externalRef: null,
			duplicatedFrom: designId,
		});
		const source = await (
			await send(fake, `/v1/designs/${designId}`, asStore)
		).json();
		expect(source.externalRef).toBe('wc:site:order:7:item:9');
	});

	it('answers 404 for a design it does not know', async () => {
		const response = await send(fake, '/v1/designs/unknown/duplicate', {
			method: 'POST',
			...asStore,
		});

		expect(response.status).toBe(404);
	});
});

describe('GET /v1/designs/{id}/proof', () => {
	async function proofOf(designId: string): Promise<string> {
		const response = await send(fake, `/v1/designs/${designId}`, asStore);
		return ((await response.json()) as { proofId: string }).proofId;
	}

	it('serves the proof `v` names as an immutable JPEG', async () => {
		const designId = await committed();
		const v = await proofOf(designId);

		const response = await send(
			fake,
			`/v1/designs/${designId}/proof?v=${v}&width=320`,
			asStore,
		);

		expect(response.status).toBe(200);
		expect(response.headers.get('content-type')).toBe('image/jpeg');
		expect(response.headers.get('cache-control')).toContain('immutable');
		expect((await response.arrayBuffer()).byteLength).toBeGreaterThan(0);
		expect(fake.violations).toEqual([]);
	});

	it('names the proof in the etag when `v` is left out', async () => {
		const designId = await committed();

		const response = await send(fake, `/v1/designs/${designId}/proof`, asStore);

		expect(response.status).toBe(200);
		expect(response.headers.get('etag')).toBe(`"${await proofOf(designId)}"`);
	});

	it('refuses a `v` naming another proof as proof_changed', async () => {
		const designId = await committed();

		const response = await send(
			fake,
			`/v1/designs/${designId}/proof?v=prf_other&width=320`,
			asStore,
		);

		expect(response.status).toBe(404);
		expect(await response.json()).toEqual({ error: 'proof_changed' });
	});

	it("answers another store's design as not found", async () => {
		const designId = await committed();

		const response = await send(
			fake,
			`/v1/designs/${designId}/proof`,
			asOtherStore,
		);

		expect(response.status).toBe(404);
		expect(await response.json()).toEqual({ error: 'not_found' });
	});
});

describe('DELETE /v1/designs/{id}', () => {
	it('deletes a committed design', async () => {
		const designId = await committed();
		const remove = { method: 'DELETE', headers: asStore.headers };

		expect((await send(fake, `/v1/designs/${designId}`, remove)).status).toBe(
			204,
		);
		expect((await send(fake, `/v1/designs/${designId}`, remove)).status).toBe(
			404,
		);
		expect(fake.violations).toEqual([]);
	});
});

describe('POST /__fake/designs', () => {
	it("seeds a committed design for the main store on its tenant's template", async () => {
		const response = await seed({ id: 'dsn_1' });

		expect(response.status).toBe(200);
		expect(await response.json()).toEqual({
			storeId: MAIN.id,
			id: 'dsn_1',
			tenantId: FIXTURE.tenant.live,
			revision: 1,
			status: 'committed',
			approvedRevision: null,
			consent: null,
			templateId: FIXTURE.template.previous,
			blankId: null,
			placementId: null,
			externalRef: null,
			previewOpened: null,
		});
	});

	it.each([
		['a store it does not have', { store: 'conn_nope' }],
		['a revision below 1', { revision: 0 }],
		['a status it does not know', { status: 'draft' }],
		["another tenant's template", { templateId: FIXTURE.template.sandbox }],
		['a placement without a blank', { placementId: FIXTURE.placement.front }],
	])('refuses %s', async (_, json) => {
		const response = await seed(json);

		expect(response.status).toBe(400);
	});
});

describe('GET /__fake/designs/{id}', () => {
	it('reads any store design with the consent it was approved with', async () => {
		const id = await committed();
		await approve(id);

		const response = await send(fake, `/__fake/designs/${id}`);

		expect(response.status).toBe(200);
		expect(await response.json()).toMatchObject({
			id,
			status: 'approved',
			consent: CONSENT,
		});
	});

	it('answers 404 for a design that does not exist', async () => {
		const response = await send(fake, '/__fake/designs/dsn_nope');

		expect(response.status).toBe(404);
	});
});

describe('POST /v1/designs/{id}/reject', () => {
	function reject(id: string, json: Record<string, unknown> = {}) {
		return send(fake, `/v1/designs/${id}/reject`, {
			method: 'POST',
			json,
			...asStore,
		});
	}

	it('rejects a committed design, and answers a repeat the same', async () => {
		await seed({ id: 'dsn_1' });

		const first = await reject('dsn_1');
		const again = await reject('dsn_1', { expectedRevision: 1 });

		expect(first.status).toBe(200);
		expect(await first.json()).toEqual({ ok: true });
		expect(again.status).toBe(200);
		const read = await send(fake, '/v1/designs/dsn_1', asStore);
		expect(await read.json()).toMatchObject({ status: 'rejected' });
		expect(fake.violations).toEqual([]);
	});

	it('refuses an approved design, and a revision the design moved past', async () => {
		await seed({ id: 'dsn_approved', status: 'approved' });
		await seed({ id: 'dsn_moved', revision: 3 });

		const approved = await reject('dsn_approved');
		const moved = await reject('dsn_moved', { expectedRevision: 2 });

		expect(approved.status).toBe(409);
		expect(await approved.json()).toEqual({ error: 'not_committed' });
		expect(moved.status).toBe(409);
		expect(await moved.json()).toEqual({
			error: 'revision_changed',
			revision: 3,
		});
		expect(fake.violations).toEqual([]);
	});

	it("answers another store's design not_found", async () => {
		await seed({ id: 'dsn_other', store: FIXTURE.store.other.id });

		const response = await reject('dsn_other');

		expect(response.status).toBe(404);
		expect(fake.violations).toEqual([]);
	});
});

describe('POST /v1/designs/{id}/approve', () => {
	it('approves a design the store committed from a proof', async () => {
		const id = await committed();

		const response = await approve(id, {
			consent: CONSENT,
			expectedRevision: 1,
		});

		expect(response.status).toBe(200);
		expect(await response.json()).toEqual({ status: 'approved' });
		const read = await send(fake, `/v1/designs/${id}`, asStore);
		expect(await read.json()).toMatchObject({
			status: 'approved',
			approvalConsent: { ...CONSENT, ref: null },
		});
		expect(fake.violations).toEqual([]);
	});

	it('approves a committed design, and approves it again on a repeat', async () => {
		await seed({ id: 'dsn_1' });
		const request = { consent: CONSENT, expectedRevision: 1 };

		const first = await approve('dsn_1', request);
		const again = await approve('dsn_1', request);

		expect(first.status).toBe(200);
		expect(await first.json()).toEqual({ status: 'approved' });
		expect(again.status).toBe(200);
		expect(await again.json()).toEqual({ status: 'approved' });
		expect(fake.violations).toEqual([]);
	});

	it('refuses an expected revision the design has moved past', async () => {
		await seed({ id: 'dsn_1', revision: 3 });

		const response = await approve('dsn_1', {
			consent: CONSENT,
			expectedRevision: 2,
		});

		expect(response.status).toBe(409);
		expect(await response.json()).toEqual({
			error: 'revision_changed',
			revision: 3,
		});
		expect(fake.violations).toEqual([]);
	});

	it('refuses a rejected design, or one approved at another revision', async () => {
		await seed({ id: 'dsn_rejected', status: 'rejected' });
		await seed({ id: 'dsn_approved', status: 'approved', revision: 2 });

		const rejected = await approve('dsn_rejected');
		const other = await approve('dsn_approved', {
			consent: CONSENT,
			expectedRevision: 1,
		});

		expect(rejected.status).toBe(409);
		expect(await rejected.json()).toEqual({ error: 'not_committed' });
		expect(other.status).toBe(409);
		expect(await other.json()).toEqual({ error: 'not_committed' });
		expect(fake.violations).toEqual([]);
	});

	it("answers another store's design and an unknown one not_found", async () => {
		await seed({ id: 'dsn_other', store: FIXTURE.store.other.id });

		for (const id of ['dsn_other', 'dsn_unknown']) {
			const response = await approve(id);

			expect(response.status).toBe(404);
			expect(await response.json()).toEqual({ error: 'not_found' });
		}
		expect(fake.violations).toEqual([]);
	});
});
