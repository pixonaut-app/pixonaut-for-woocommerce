import { beforeEach, describe, expect, it } from 'vitest';
import { createFake } from '../../fake-pixonaut/src/app';
import { LINK_BEARER_PREFIX } from '../../fake-pixonaut/src/auth';
import { FIXTURE } from '../../fake-pixonaut/src/fixture-ids';
import { CORRECTION_LINK_LIFETIME_MS } from '../../fake-pixonaut/src/handlers/corrections';
import { findDesign } from '../../fake-pixonaut/src/handlers/designs-store';
import { PROOF_LIFETIME_MS } from '../../fake-pixonaut/src/proofs';
import {
	bearer,
	FOREIGN_ORIGIN,
	MAIN,
	OWN_FAKE_TIMEOUT_MS,
	SITE_ORIGIN,
	SITE_URL,
	send,
} from './support';

const fake = createFake();
const asStore = { headers: bearer(MAIN.integrationKey) };
const asOtherStore = { headers: bearer(FIXTURE.store.other.integrationKey) };

/** A customer's correction: new wording for the tee's slogan. */
const VALUES = { slogan: { content: 'Ny text' } };

beforeEach(() => {
	fake.reset('default', SITE_URL);
});

async function seed(json: Record<string, unknown> = {}): Promise<string> {
	const response = await send(fake, '/__fake/designs', {
		method: 'POST',
		json,
	});
	expect(response.status).toBe(200);
	return ((await response.json()) as { id: string }).id;
}

function mintLink(id: string, headers = asStore.headers, json = {}) {
	return send(fake, `/v1/designs/${id}/correction-links`, {
		method: 'POST',
		headers,
		json,
	});
}

async function link(id: string): Promise<string> {
	const response = await mintLink(id);
	expect(response.status).toBe(201);
	return ((await response.json()) as { token: string }).token;
}

function asLink(token: string, origin = SITE_ORIGIN) {
	return { ...bearer(`${LINK_BEARER_PREFIX}${token}`), origin };
}

function proofCorrection(
	id: string,
	token: string,
	json: Record<string, unknown> = { values: VALUES, expectedRevision: 1 },
) {
	return send(fake, `/v1/designs/${id}/correction-proofs`, {
		method: 'POST',
		headers: asLink(token),
		json,
	});
}

async function correctionProof(id: string, revision = 1): Promise<string> {
	const response = await proofCorrection(id, await link(id), {
		values: VALUES,
		expectedRevision: revision,
	});
	expect(response.status).toBe(201);
	return ((await response.json()) as { proofToken: string }).proofToken;
}

function patch(id: string, json: Record<string, unknown>) {
	return send(fake, `/v1/designs/${id}`, {
		method: 'PATCH',
		...asStore,
		json,
	});
}

async function design(id: string) {
	const response = await send(fake, `/v1/designs/${id}`, asStore);
	return (await response.json()) as {
		revision: number;
		proofId: string;
		status: string;
	};
}

function advance(advanceMs: number) {
	return send(fake, '/__fake/clock', { method: 'POST', json: { advanceMs } });
}

describe('correction links', () => {
	it('hands out a 2 hour link for the design, with no page without a return address', async () => {
		const id = await seed();
		const before = fake.clock.now();
		const response = await mintLink(id);

		expect(response.status).toBe(201);
		const body = await response.json();
		// The SDK sends it behind `link_`.
		expect(body.token).toMatch(/^[0-9a-f]{32}$/);
		expect(body.url).toBeNull();
		expect(body.expiresAt).toBeGreaterThanOrEqual(
			before + CORRECTION_LINK_LIFETIME_MS,
		);
		expect(body.expiresAt).toBeLessThanOrEqual(
			fake.clock.now() + CORRECTION_LINK_LIFETIME_MS,
		);
		expect(fake.violations).toEqual([]);
	});

	it('refuses a rejected design, and another store’s', async () => {
		const rejected = await seed({ status: 'rejected' });
		expect((await mintLink(rejected)).status).toBe(409);
		expect((await mintLink(await seed(), asOtherStore.headers)).status).toBe(
			404,
		);
		expect(fake.violations).toEqual([]);
	});

	it('opens its design in the designer from the store’s origin, and no other design', async () => {
		const id = await seed();
		const token = await link(id);

		const response = await send(fake, `/v1/designs/${id}/designer`, {
			headers: asLink(token),
		});
		expect(response.status).toBe(200);
		const body = await response.json();
		expect(body.revision).toBe(1);
		expect(body.doc.templateId).toBe(
			`${body.template.id}@${body.template.version}`,
		);
		expect(body.template.id).toBe('mini-tee');

		const other = await seed();
		expect(
			(
				await send(fake, `/v1/designs/${other}/designer`, {
					headers: asLink(token),
				})
			).status,
		).toBe(404);
		expect(
			(
				await send(fake, `/v1/designs/${id}/designer`, {
					headers: asLink(token, FOREIGN_ORIGIN),
				})
			).status,
		).toBe(403);
		expect(fake.violations).toEqual([]);
	});

	it('names the blank and placement its design was made on, or none', async () => {
		const onTee = await seed({
			blankId: FIXTURE.blank.tee,
			placementId: FIXTURE.placement.front,
		});
		const onNone = await seed();

		const read = async (id: string) =>
			(await send(fake, `/v1/designs/${id}/designer`, {
				headers: asLink(await link(id)),
			}).then((r) => r.json())) as { blankId: unknown; placementId: unknown };

		expect(await read(onTee)).toMatchObject({
			blankId: FIXTURE.blank.tee,
			placementId: FIXTURE.placement.front,
		});
		expect(await read(onNone)).toMatchObject({
			blankId: null,
			placementId: null,
		});
		expect(fake.violations).toEqual([]);
	});

	it('reads its design’s template on the placement it was made on, and no other', async () => {
		const id = await seed({
			blankId: FIXTURE.blank.tee,
			placementId: FIXTURE.placement.front,
		});
		const headers = asLink(await link(id));
		const { template } = (await send(fake, `/v1/designs/${id}/designer`, {
			headers,
		}).then((r) => r.json())) as {
			template: { id: string; version: number };
		};
		const constraints = (placementId: string) =>
			send(
				fake,
				`/v1/templates/${encodeURIComponent(`${template.id}@${template.version}`)}/constraints?blankId=${FIXTURE.blank.tee}&placementId=${placementId}`,
				{ headers },
			);

		expect((await constraints(FIXTURE.placement.front)).status).toBe(200);
		expect((await constraints(FIXTURE.placement.back)).status).toBe(404);
		expect(fake.violations).toEqual([]);
	});

	it('reads the blank its design was made on, and no other', async () => {
		const id = await seed({
			blankId: FIXTURE.blank.tee,
			placementId: FIXTURE.placement.front,
		});
		const onNone = await seed();
		const headers = asLink(await link(id));
		const read = (path: string, as = headers) =>
			send(fake, path, { headers: as }).then((r) => r.status);
		const tee = `/v1/blanks/${FIXTURE.blank.tee}`;
		const hoodie = `/v1/blanks/${FIXTURE.blank.hoodie}`;

		expect(await read(`${tee}/mockups`)).toBe(200);
		expect(await read(`${tee}/variants`)).toBe(200);
		expect(await read(`${hoodie}/mockups`)).toBe(404);
		expect(await read(`${hoodie}/variants`)).toBe(404);
		expect(await read(`${tee}/mockups`, asLink(await link(onNone)))).toBe(404);
		expect(fake.violations).toEqual([]);
	});

	it('reads no preview tables, the fake drawing every placement through a bundled profile', async () => {
		const id = await seed({
			blankId: FIXTURE.blank.tee,
			placementId: FIXTURE.placement.front,
		});
		const headers = asLink(await link(id));
		const tables = (placementId: string) =>
			send(
				fake,
				`/v1/blanks/${FIXTURE.blank.tee}/placements/${placementId}/preview-tables`,
				{ headers },
			).then((r) => r.json());

		expect(await tables(FIXTURE.placement.front)).toEqual({
			error: 'no_preview_tables',
		});
		expect(await tables(FIXTURE.placement.back)).toEqual({
			error: 'not_found',
		});
		expect(fake.violations).toEqual([]);
	});

	it('stops working after 2 hours', async () => {
		const id = await seed();
		const token = await link(id);
		await advance(CORRECTION_LINK_LIFETIME_MS);

		expect(
			(
				await send(fake, `/v1/designs/${id}/designer`, {
					headers: asLink(token),
				})
			).status,
		).toBe(401);
		expect((await proofCorrection(id, token)).status).toBe(401);
	});
});

describe('correction proofs and PATCH', () => {
	it('proofs a correction without writing it', async () => {
		const id = await seed();
		const before = await design(id);
		const response = await proofCorrection(id, await link(id));

		expect(response.status).toBe(201);
		const body = await response.json();
		expect(body.revision).toBe(1);
		expect(body.proofToken).toEqual(expect.any(String));
		expect(await design(id)).toEqual(before);
		expect(fake.violations).toEqual([]);
	});

	it('refuses a proof of a revision the design moved past', async () => {
		const id = await seed({ revision: 2 });
		const response = await proofCorrection(id, await link(id));

		expect(response.status).toBe(409);
		expect((await response.json()).error).toBe('revision_changed');
	});

	it('makes the proof the design’s next revision and clears its approval', async () => {
		const id = await seed({ status: 'approved' });
		const before = await design(id);
		const response = await patch(id, { proofToken: await correctionProof(id) });

		expect(response.status).toBe(200);
		const body = await response.json();
		expect(body).toMatchObject({ revision: 2, edited: true });
		expect(body.proofId).not.toBe(before.proofId);
		expect(await design(id)).toMatchObject({
			revision: 2,
			proofId: body.proofId,
			status: 'committed',
		});
		expect(fake.violations).toEqual([]);
	});

	it('keeps the variant and view the design was previewed on', async () => {
		const id = await seed();
		const seeded = findDesign(fake.state, id, MAIN.id);
		if (seeded === undefined) {
			throw new Error(`No design ${id}.`);
		}
		seeded.proof.variant = FIXTURE.variant.teeBlackM;
		seeded.proof.viewId = 'view_front_dark';

		const response = await patch(id, { proofToken: await correctionProof(id) });

		expect(response.status).toBe(200);
		expect(await design(id)).toMatchObject({
			revision: 2,
			proofVariant: FIXTURE.variant.teeBlackM,
			proofViewId: 'view_front_dark',
		});
		expect(fake.violations).toEqual([]);
	});

	it('keeps the design’s values a later correction leaves alone', async () => {
		const id = await seed();
		await patch(id, { proofToken: await correctionProof(id) });
		const uploaded = { motiv: { src: 'https://cdn.example/motiv.png' } };
		const second = await proofCorrection(id, await link(id), {
			values: uploaded,
			expectedRevision: 2,
		});
		const { proofToken } = (await second.json()) as { proofToken: string };
		expect((await patch(id, { proofToken })).status).toBe(200);

		expect(findDesign(fake.state, id, MAIN.id)?.proof.values).toEqual({
			...VALUES,
			...uploaded,
		});
		expect(fake.violations).toEqual([]);
	});

	it('opens a corrected design on its corrected wording', async () => {
		const id = await seed();
		await patch(id, { proofToken: await correctionProof(id) });

		const response = await send(fake, `/v1/designs/${id}/designer`, {
			headers: asLink(await link(id)),
		});
		const { doc } = (await response.json()) as {
			doc: { layers: { id: string; kind: Record<string, unknown> }[] };
		};
		const slogan = doc.layers.find((layer) => layer.id === 'slogan');
		expect(slogan?.kind.content).toBe(VALUES.slogan.content);
		expect(slogan?.kind.fontFamily).toBe('Poppins');
		expect(fake.violations).toEqual([]);
	});

	it('refuses a proof made before the design was corrected again', async () => {
		const id = await seed();
		const first = await correctionProof(id);
		const second = await correctionProof(id);
		expect((await patch(id, { proofToken: first })).status).toBe(200);

		const response = await patch(id, { proofToken: second });
		expect(response.status).toBe(409);
		expect((await response.json()).error).toBe('revision_changed');
		expect(fake.violations).toEqual([]);
	});

	it('refuses an expired proof, another design’s and an unknown one', async () => {
		const id = await seed();
		const expired = await correctionProof(id);
		const another = await correctionProof(await seed());
		await advance(PROOF_LIFETIME_MS);

		expect((await patch(id, { proofToken: expired })).status).toBe(410);
		const elsewhere = await patch(id, { proofToken: another });
		expect(elsewhere.status).toBe(409);
		expect((await elsewhere.json()).error).toBe('proof_not_for_design');
		expect((await patch(id, { proofToken: 'prf.unknown' })).status).toBe(404);
		expect(fake.violations).toEqual([]);
	});

	it('refuses a rejected design as not editable', async () => {
		const id = await seed();
		const token = await correctionProof(id);
		await send(fake, '/__fake/designs', {
			method: 'POST',
			json: { id, status: 'rejected' },
		});

		const response = await patch(id, { proofToken: token });
		expect(response.status).toBe(409);
		expect((await response.json()).error).toBe('not_editable');
	});
});

describe('the hosted correction page', () => {
	const RETURN_URL = `${SITE_URL}/?pixonaut_corrected=cart`;
	const TRIP = 'trip-1';

	/** The hosted page's path for a link minted with a return address. */
	async function pagePath(id: string): Promise<string> {
		const response = await mintLink(id, asStore.headers, {
			returnUrl: RETURN_URL,
			state: TRIP,
		});
		expect(response.status).toBe(201);
		const { url } = (await response.json()) as { url: string };
		return new URL(url).pathname;
	}

	/** Follows one of the page's actions to the store, as the browser would. */
	async function act(path: string, query = ''): Promise<URL> {
		const response = await send(fake, `${path}${query}`);
		expect(response.status).toBe(302);
		return new URL(response.headers.get('location') ?? '');
	}

	it('opens under the link’s token, and returns the state and a correction proof the store redeems', async () => {
		const id = await seed();
		const path = await pagePath(id);
		expect(path).toMatch(/^\/h\/correct\/[0-9a-f]{32}$/);
		expect((await send(fake, path)).status).toBe(200);

		const back = await act(`${path}/approve`, '?text=Ny%20text');

		expect(back.origin).toBe(SITE_ORIGIN);
		expect(back.searchParams.get('pixonaut_corrected')).toBe('cart');
		expect(back.searchParams.get('state')).toBe(TRIP);
		const proofToken = back.searchParams.get('proofToken') ?? '';
		// Approving writes nothing to the design; the store's PATCH does.
		expect((await design(id)).revision).toBe(1);
		const response = await patch(id, { proofToken });
		expect(response.status).toBe(200);
		expect(await response.json()).toMatchObject({ revision: 2, edited: true });
		expect(fake.violations).toEqual([]);
	});

	it('sends a cancelling customer back with the state alone', async () => {
		const back = await act(`${await pagePath(await seed())}/cancel`);

		expect(back.searchParams.get('state')).toBe(TRIP);
		expect(back.searchParams.has('proofToken')).toBe(false);
	});

	it('stops working with its link, and for a rejected design', async () => {
		const id = await seed();
		const path = await pagePath(id);
		await advance(CORRECTION_LINK_LIFETIME_MS);
		expect((await send(fake, path)).status).toBe(410);

		const rejected = await seed();
		const open = await pagePath(rejected);
		await send(fake, '/__fake/designs', {
			method: 'POST',
			json: { id: rejected, status: 'rejected' },
		});
		expect((await send(fake, open)).status).toBe(409);
		expect((await send(fake, `${open}/approve`)).status).toBe(409);
	});

	it('is not opened by a link minted without a return address', async () => {
		const token = await link(await seed());

		expect((await send(fake, `/h/correct/${token}`)).status).toBe(410);
	});

	it('refuses a return address off the store, and a state without one', async () => {
		const id = await seed();
		const off = await mintLink(id, asStore.headers, {
			returnUrl: `${FOREIGN_ORIGIN}/back`,
			state: TRIP,
		});
		expect(off.status).toBe(400);
		expect(await off.json()).toEqual({ error: 'return_url_off_store' });

		const bare = await mintLink(id, asStore.headers, { state: TRIP });
		expect(bare.status).toBe(400);
		expect(await bare.json()).toEqual({ error: 'invalid_input' });
	});

	it(
		'is on the public origin the fake is given, where the browser reaches it',
		async () => {
			const publicFake = createFake({ publicOrigin: 'http://localhost:8924' });
			publicFake.reset('default', SITE_URL);
			const seeded = await send(publicFake, '/__fake/designs', {
				method: 'POST',
				json: {},
			});
			const { id } = (await seeded.json()) as { id: string };

			const response = await send(
				publicFake,
				`/v1/designs/${id}/correction-links`,
				{ method: 'POST', ...asStore, json: { returnUrl: RETURN_URL } },
			);

			expect(response.status).toBe(201);
			const { url } = (await response.json()) as { url: string };
			expect(url).toMatch(/^http:\/\/localhost:8924\/h\/correct\//);
		},
		OWN_FAKE_TIMEOUT_MS,
	);
});
