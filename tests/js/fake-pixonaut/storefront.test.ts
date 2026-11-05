import { afterEach, beforeEach, describe, expect, it } from 'vitest';
import { createFake } from '../../fake-pixonaut/src/app';
import { FIXTURE } from '../../fake-pixonaut/src/fixture-ids';
import {
	findAsset,
	IMPROVEMENT_SCALE,
	UPLOAD_LIFETIME_MS,
} from '../../fake-pixonaut/src/handlers/assets';
import { findProof, PROOF_LIFETIME_MS } from '../../fake-pixonaut/src/proofs';
import {
	declareUpload,
	OTHER_SESSION,
	PICTURE,
	PICTURE_HEIGHT_PX,
	PICTURE_WIDTH_PX,
	picture,
	putUpload,
	SESSION,
	storefront,
	uploadPicture,
} from './storefront-support';
import {
	bearer,
	FOREIGN_ORIGIN,
	MAIN,
	type Sent,
	SITE_ORIGIN,
	SITE_URL,
	send,
} from './support';

/** The fake's clock, standing still so an expiry can be compared exactly. */
const NOW = Date.UTC(2026, 9, 14, 12);
const fake = createFake({ realNow: () => NOW });

/** The fake's proof image is a square JPEG this many pixels on a side. */
const PROOF_IMAGE_EDGE_PX = 8;

const onBack = {
	blankId: FIXTURE.blank.tee,
	placementId: FIXTURE.placement.back,
};

function proof(json: Record<string, unknown>, sent: Sent = {}) {
	return send(fake, '/v1/proofs', {
		method: 'POST',
		headers: storefront(),
		json: { templateId: FIXTURE.template.live, values: {}, ...json },
		...sent,
	});
}

const declare = (headers?: Record<string, string>) =>
	declareUpload(fake, headers);
const put = (uploadUrl: string, body?: Uint8Array<ArrayBuffer>) =>
	putUpload(fake, uploadUrl, body);
const upload = (headers?: Record<string, string>) =>
	uploadPicture(fake, headers);

/** Commits a proof token as the main store's server does at add to cart. */
function commit(proofToken: string) {
	return send(fake, '/v1/designs', {
		method: 'POST',
		headers: {
			...bearer(MAIN.integrationKey),
			'idempotency-key': `commit:${proofToken}`,
		},
		json: { proofToken },
	});
}

function improve(assetId: string, headers = storefront()) {
	return send(fake, `/v1/assets/${assetId}/improve-resolution`, {
		method: 'POST',
		headers,
	});
}

beforeEach(() => {
	fake.reset('default', SITE_URL);
});

afterEach(() => {
	expect(fake.violations).toEqual([]);
});

describe('POST /v1/proofs', () => {
	it('answers a proof expiring a proof lifetime from the clock', async () => {
		const response = await proof({});

		expect(response.status).toBe(201);
		const body = await response.json();
		expect(body).toEqual({
			proofToken: expect.any(String),
			proofUrl: `http://fake.test/p/${body.proofToken}`,
			docHash: expect.any(String),
			expiresAt: NOW + PROOF_LIFETIME_MS,
			printFloorDpi: 150,
			lowResolution: [],
		});
	});

	it('keeps the proof for its commit, fitted to the placement it names', async () => {
		const values = { headline: { content: 'Hello' } };
		const body = await (await proof({ ...onBack, values })).json();

		expect(findProof(fake.state, MAIN.id, body.proofToken)).toMatchObject({
			token: body.proofToken,
			template: { baseId: 'mini-tee', version: 2 },
			...onBack,
			printSize: { widthMm: 280, heightMm: 360 },
			values,
			docHash: body.docHash,
			expiresAt: body.expiresAt,
			sessionId: SESSION,
		});
		expect(findProof(fake.state, FIXTURE.store.other.id, body.proofToken)).toBe(
			undefined,
		);
	});

	it("commits as the store's design, from the placement the proof was fitted to", async () => {
		const { proofToken } = await (await proof(onBack)).json();

		const committed = await commit(proofToken);

		expect(committed.status).toBe(201);
		const { designId } = await committed.json();
		const design = await send(fake, `/v1/designs/${designId}`, {
			headers: bearer(MAIN.integrationKey),
		});
		expect(await design.json()).toMatchObject({
			blankId: onBack.blankId,
			placementId: onBack.placementId,
			printSize: { widthMm: 280, heightMm: 360 },
		});
	});

	it('keeps the variant and view the customer previewed, which the design reads back', async () => {
		const preview = {
			variant: FIXTURE.variant.teeBlackM,
			viewId: 'view_back',
		};
		const { proofToken } = await (
			await proof({ ...onBack, ...preview })
		).json();

		expect(findProof(fake.state, MAIN.id, proofToken)).toMatchObject(preview);
		const { designId } = await (await commit(proofToken)).json();
		const design = await send(fake, `/v1/designs/${designId}`, {
			headers: bearer(MAIN.integrationKey),
		});
		expect(await design.json()).toMatchObject({
			proofVariant: preview.variant,
			proofViewId: preview.viewId,
		});
	});

	it('keeps a previewed variant unchecked, and reads none back as null', async () => {
		const named = await (
			await proof({ ...onBack, variant: 'v_not_on_the_blank' })
		).json();
		const unnamed = await (await proof(onBack)).json();

		expect(findProof(fake.state, MAIN.id, named.proofToken)).toMatchObject({
			variant: 'v_not_on_the_blank',
			viewId: null,
		});
		const { designId } = await (await commit(unnamed.proofToken)).json();
		const design = await send(fake, `/v1/designs/${designId}`, {
			headers: bearer(MAIN.integrationKey),
		});
		expect(await design.json()).toMatchObject({
			proofVariant: null,
			proofViewId: null,
		});
	});

	it('keeps the size the request names over the fitted one', async () => {
		const size = { widthMm: 200, heightMm: 250 };
		const body = await (await proof({ ...onBack, ...size })).json();

		expect(findProof(fake.state, MAIN.id, body.proofToken)?.printSize).toEqual(
			size,
		);
	});

	it('keeps no size and no session when the request names neither', async () => {
		const response = await proof(
			{},
			{ headers: { ...bearer(MAIN.publishableKey), origin: SITE_ORIGIN } },
		);
		const record = findProof(
			fake.state,
			MAIN.id,
			(await response.json()).proofToken,
		);

		expect(record).toMatchObject({
			blankId: null,
			placementId: null,
			printSize: null,
			sessionId: null,
		});
	});

	it('serves the proof image keyless until the proof expires', async () => {
		const { proofUrl } = await (await proof({})).json();
		const path = new URL(proofUrl).pathname;

		const image = await send(fake, path, { headers: { origin: SITE_ORIGIN } });
		expect(image.status).toBe(200);
		expect(image.headers.get('content-type')).toBe('image/jpeg');
		expect(image.headers.get('access-control-allow-origin')).toBe(SITE_ORIGIN);

		fake.clock.advance(PROOF_LIFETIME_MS);
		expect((await send(fake, path)).status).toBe(404);
	});

	it.each([
		['a blank without a placement', { blankId: FIXTURE.blank.tee }],
		['a placement without a blank', { placementId: FIXTURE.placement.back }],
		['a width without a height', { widthMm: 200 }],
	])('refuses %s as bad_request', async (_, half) => {
		const response = await proof(half);

		expect(response.status).toBe(400);
		expect(await response.json()).toEqual({ error: 'bad_request' });
	});

	it('refuses a placement the blank does not have', async () => {
		const response = await proof({
			blankId: FIXTURE.blank.tee,
			placementId: 'sleeve',
		});

		expect(response.status).toBe(422);
		expect(await response.json()).toEqual({ error: 'unknown_placement' });
	});

	it("judges another tenant's template as an unknown one", async () => {
		const response = await proof({ templateId: FIXTURE.template.sandbox });

		expect(response.status).toBe(422);
		expect(await response.json()).toEqual({
			error: 'constraint_violation',
			violations: [
				{
					layerId: '',
					prop: 'templateId',
					rule: 'unknown_template',
					given: FIXTURE.template.sandbox,
					severity: 'error',
				},
			],
		});
	});

	it('refuses a field held to a change that keeps its default', async () => {
		const response = await proof({
			templateId: FIXTURE.template.requireChange,
		});

		expect(response.status).toBe(422);
		expect(await response.json()).toEqual({
			error: 'constraint_violation',
			violations: [
				{
					layerId: 'slogan',
					prop: 'content',
					rule: 'unchanged',
					given: 'Din text här',
					severity: 'error',
				},
			],
		});
	});

	it('makes the proof once the field held to a change is changed', async () => {
		const response = await proof({
			templateId: FIXTURE.template.requireChange,
			values: { slogan: { content: 'Klass 9B' } },
		});

		expect(response.status).toBe(201);
	});

	it('answers a designer link not_found for a template it does not reach', async () => {
		const response = await proof(
			{ templateId: FIXTURE.template.previous },
			{ headers: bearer(FIXTURE.shareLink) },
		);

		expect(response.status).toBe(404);
		expect(await response.json()).toEqual({ error: 'not_found' });
	});

	it('refuses a publishable key from an origin its store does not allow', async () => {
		const response = await proof(
			{},
			{ headers: { ...storefront(), origin: FOREIGN_ORIGIN } },
		);

		expect(response.status).toBe(403);
		expect(await response.json()).toEqual({ error: 'origin_forbidden' });
	});
});

describe('uploading a picture', () => {
	it('declares, uploads and serves the bytes back', async () => {
		const { assetId, uploadUrl } = await declare();
		expect(assetId).toMatch(/^up_[0-9a-f]+$/);
		expect(uploadUrl).toMatch(/^\/u\//);

		const uploaded = await put(uploadUrl);
		expect(uploaded.status).toBe(200);
		expect(uploaded.headers.get('access-control-allow-origin')).toBe(
			SITE_ORIGIN,
		);
		const meta = await uploaded.json();
		expect(meta).toMatchObject({
			assetId,
			widthPx: PICTURE_WIDTH_PX,
			heightPx: PICTURE_HEIGHT_PX,
			improveResolution: null,
		});

		const content = await send(fake, `/v1/assets/${assetId}/content`, {
			headers: storefront(),
		});
		expect(content.status).toBe(200);
		expect(content.headers.get('content-type')).toBe('image/png');
		expect(content.headers.get('x-pixonaut-original-width')).toBe(
			String(PICTURE_WIDTH_PX),
		);
		expect(Buffer.from(await content.arrayBuffer())).toEqual(PICTURE);
		expect(findAsset(fake.state, MAIN.id, assetId)?.sessionId).toBe(SESSION);
	});

	it("reads a JPEG's size too", async () => {
		const { proofUrl } = await (await proof({})).json();
		const jpeg = new Uint8Array(
			await (await send(fake, new URL(proofUrl).pathname)).arrayBuffer(),
		);
		const declared = await send(fake, '/v1/assets', {
			method: 'POST',
			headers: storefront(),
			json: { mime: 'image/jpeg', sizeBytes: jpeg.length },
		});
		const { uploadUrl } = await declared.json();

		const uploaded = await send(fake, uploadUrl, {
			method: 'PUT',
			headers: { 'content-type': 'image/jpeg' },
			body: jpeg,
		});

		expect(await uploaded.json()).toMatchObject({
			widthPx: PROOF_IMAGE_EDGE_PX,
			heightPx: PROOF_IMAGE_EDGE_PX,
		});
	});

	it('caches the content for good when asked by its contentKey', async () => {
		const { assetId, uploadUrl } = await declare();
		const { contentKey } = await (await put(uploadUrl)).json();

		const content = await send(
			fake,
			`/v1/assets/${assetId}/content?v=${contentKey}`,
			{ headers: storefront() },
		);

		expect(content.headers.get('cache-control')).toContain('immutable');
	});

	it("answers another store's asset with a bare 404", async () => {
		const assetId = await upload();

		const content = await send(fake, `/v1/assets/${assetId}/content`, {
			headers: {
				...bearer(FIXTURE.store.other.publishableKey),
				origin: 'http://other-store.test',
			},
		});

		expect(content.status).toBe(404);
	});

	it('takes the bytes once', async () => {
		const { uploadUrl } = await declare();
		await put(uploadUrl);

		const again = await put(uploadUrl);

		expect(again.status).toBe(404);
		expect(await again.json()).toEqual({ error: 'unknown_upload' });
	});

	it('refuses an upload URL past its lifetime', async () => {
		const { uploadUrl } = await declare();
		fake.clock.advance(UPLOAD_LIFETIME_MS);

		const late = await put(uploadUrl);

		expect(late.status).toBe(410);
		expect(await late.json()).toEqual({ error: 'upload_expired' });
	});

	it('refuses bytes other than the size declared', async () => {
		const { uploadUrl } = await declare();

		const short = await put(uploadUrl, picture().subarray(1));

		expect(short.status).toBe(422);
		expect(await short.json()).toEqual({ error: 'size_mismatch' });
	});

	it('refuses bytes that are no image', async () => {
		const { uploadUrl } = await declare();

		const noise = await put(uploadUrl, new Uint8Array(PICTURE.length));

		expect(noise.status).toBe(422);
		expect(await noise.json()).toEqual({ error: 'not_a_valid_image' });
	});

	it('refuses an upload for a template the caller cannot see', async () => {
		const response = await send(fake, '/v1/assets', {
			method: 'POST',
			headers: storefront(),
			json: {
				mime: 'image/png',
				sizeBytes: PICTURE.length,
				templateId: FIXTURE.template.sandbox,
			},
		});

		expect(response.status).toBe(404);
		expect(await response.json()).toEqual({ error: 'not_found' });
	});
});

describe('POST /v1/assets/{id}/improve-resolution', () => {
	it('makes a larger upload of the picture, once', async () => {
		const source = await upload();

		const first = await improve(source);
		expect(first.status).toBe(201);
		const improved = await first.json();
		expect(improved).toEqual({
			assetId: expect.stringMatching(/^up_/),
			sourceAssetId: source,
			widthPx: PICTURE_WIDTH_PX * IMPROVEMENT_SCALE,
			heightPx: PICTURE_HEIGHT_PX * IMPROVEMENT_SCALE,
			contentKey: expect.any(String),
		});
		expect(improved.assetId).not.toBe(source);

		const again = await improve(source);
		expect(again.status).toBe(200);
		expect(await again.json()).toEqual(improved);

		const content = await send(fake, `/v1/assets/${improved.assetId}/content`, {
			headers: storefront(),
		});
		expect(content.status).toBe(200);
	});

	it('does not improve an improvement', async () => {
		const { assetId } = await (await improve(await upload())).json();

		const response = await improve(assetId);

		expect(response.status).toBe(422);
		expect(await response.json()).toEqual({ error: 'not_improvable' });
	});

	it("answers another session's upload not_found", async () => {
		const source = await upload();

		const response = await improve(source, storefront(OTHER_SESSION));

		expect(response.status).toBe(404);
		expect(await response.json()).toEqual({ error: 'not_found' });
	});
});

describe('DELETE /v1/sessions/{id}', () => {
	it("deletes the session's uploads and proofs, and nothing else", async () => {
		const mine = [await upload(), await upload()];
		const { proofToken } = await (await proof({})).json();
		const otherSession = await upload(storefront(OTHER_SESSION));
		const otherStore = await upload({
			...bearer(FIXTURE.store.other.publishableKey),
			origin: 'http://other-store.test',
			'x-pixonaut-session': SESSION,
		});
		const held = await upload();
		const heldRecord = findAsset(fake.state, MAIN.id, held);
		Object.assign(heldRecord ?? {}, { retained: true });
		const { proofToken: committedToken } = await (await proof({})).json();
		expect((await commit(committedToken)).status).toBe(201);

		const response = await send(fake, `/v1/sessions/${SESSION}`, {
			method: 'DELETE',
			headers: storefront(),
		});

		expect(response.status).toBe(200);
		expect(await response.json()).toEqual({
			assetsDeleted: mine.length,
			proofsDeleted: 1,
		});
		for (const id of mine) {
			expect(findAsset(fake.state, MAIN.id, id)).toBe(undefined);
		}
		expect(findProof(fake.state, MAIN.id, proofToken)).toBe(undefined);
		expect(findProof(fake.state, MAIN.id, committedToken)).toBeDefined();
		expect(findAsset(fake.state, MAIN.id, otherSession)).toBeDefined();
		expect(findAsset(fake.state, MAIN.id, held)).toBeDefined();
		expect(
			findAsset(fake.state, FIXTURE.store.other.id, otherStore),
		).toBeDefined();
	});
});
