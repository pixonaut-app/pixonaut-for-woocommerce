import { afterEach, beforeEach, describe, expect, it } from 'vitest';
import { createFake } from '../../fake-pixonaut/src/app';
import { FIXTURE } from '../../fake-pixonaut/src/fixture-ids';
import {
	bearer,
	FOREIGN_ORIGIN,
	MAIN,
	OWN_FAKE_TIMEOUT_MS,
	SITE_ORIGIN,
	SITE_URL,
	send,
	templatePath,
} from './support';

const fake = createFake();
const asStore = { headers: bearer(MAIN.integrationKey) };
const asSandbox = {
	headers: bearer(FIXTURE.store.sandbox.integrationKey),
};
/** The main store's storefront: its publishable key, from its own site. */
const asStorefront = {
	headers: { ...bearer(MAIN.publishableKey), origin: SITE_ORIGIN },
};

/** The fixture tee's names, as their template documents give them. */
const TEE_NAME = 'T-shirt med tryck, framsida';
const POSTER_NAME = 'Poster, liggande';

/** The fixture placements' default box, which a sizeless print fits into. */
const DEFAULT_BOX = { widthMm: 300, heightMm: 360 };
/** The tee's 280:360 artwork fitted into the default box: full height. */
const TEE_FITTED = { widthMm: 280, heightMm: 360 };
/** The poster's 420:297 artwork fitted into the default box: full width. */
const POSTER_FITTED = {
	widthMm: DEFAULT_BOX.widthMm,
	heightMm: (DEFAULT_BOX.widthMm * 297) / 420,
};

beforeEach(() => {
	fake.reset('default', SITE_URL);
});

afterEach(() => {
	expect(fake.violations).toEqual([]);
});

function placementOf(blankId: string, placementId: string) {
	const placement = fake.state.blanks
		.get(blankId)
		?.placements.find((p) => p.id === placementId);
	if (placement === undefined) {
		throw new Error(`No placement ${blankId}/${placementId}.`);
	}
	return placement;
}

describe('GET /v1/templates', () => {
	it("pages the tenant's live templates by name, one per base", async () => {
		const response = await send(fake, '/v1/templates?status=live', asStore);

		expect(response.status).toBe(200);
		const body = await response.json();
		expect(body).toMatchObject({ total: 3, page: 1, pageSize: 50 });
		expect(body.items).toEqual([
			{
				id: 'mini-poster@1',
				baseId: 'mini-poster',
				name: POSTER_NAME,
				latestVersion: 1,
				ratio: { w: 420, h: 297 },
				ratioTolerance: 0.05,
				fieldKeys: [],
				offersProperties: true,
				backgroundSeeThrough: null,
				previewUrl: expect.stringMatching(/^http:\/\/fake\.test\/i\//),
			},
			{
				id: 'mini-tee@2',
				baseId: 'mini-tee',
				name: TEE_NAME,
				latestVersion: 2,
				ratio: { w: 280, h: 360 },
				ratioTolerance: 0,
				fieldKeys: [],
				offersProperties: true,
				backgroundSeeThrough: null,
				previewUrl: expect.stringMatching(/^http:\/\/fake\.test\/i\//),
			},
			{
				id: FIXTURE.template.requireChange,
				baseId: 'mini-tee-change',
				name: TEE_NAME,
				latestVersion: 1,
				ratio: { w: 280, h: 360 },
				ratioTolerance: 0,
				fieldKeys: [],
				offersProperties: true,
				backgroundSeeThrough: null,
				previewUrl: expect.stringMatching(/^http:\/\/fake\.test\/i\//),
			},
		]);
	});

	it('keeps the templates whose name holds q, case aside', async () => {
		const response = await send(fake, '/v1/templates?q=POSTER', asStore);

		const body = await response.json();
		expect(body.total).toBe(1);
		expect(body.items.map((t: { baseId: string }) => t.baseId)).toEqual([
			'mini-poster',
		]);
	});

	it('serves the page asked for, with the total of every page', async () => {
		const response = await send(
			fake,
			'/v1/templates?page=2&pageSize=1',
			asStore,
		);

		const body = await response.json();
		expect(body).toMatchObject({ total: 3, page: 2, pageSize: 1 });
		expect(body.items.map((t: { baseId: string }) => t.baseId)).toEqual([
			'mini-tee',
		]);
	});

	it("lists only the caller's tenant", async () => {
		const response = await send(fake, '/v1/templates', asSandbox);

		const body = await response.json();
		expect(body.items.map((t: { baseId: string }) => t.baseId)).toEqual([
			'sandbox-tee',
		]);
	});

	it("answers a key's page whatever a member's archive is asked", async () => {
		const response = await send(
			fake,
			'/v1/templates?category=none&tag=summer&tag=sale&sort=designs&groupBy=status&pageOf=mini-tee',
			asStore,
		);

		expect(response.status).toBe(200);
		const body = await response.json();
		expect(Object.keys(body).sort()).toEqual([
			'items',
			'page',
			'pageSize',
			'total',
		]);
		expect(body).toMatchObject({ total: 3, page: 1, pageSize: 50 });
		expect(body.items.map((t: { baseId: string }) => t.baseId)).toEqual([
			'mini-poster',
			'mini-tee',
			'mini-tee-change',
		]);
	});

	it.each(['paused', 'all', 'retired'])(
		'refuses status=%s to a key',
		async (status) => {
			const response = await send(
				fake,
				`/v1/templates?status=${status}`,
				asStore,
			);

			expect(response.status).toBe(400);
			expect(await response.json()).toEqual({ error: 'invalid_status' });
		},
	);
});

describe('GET /v1/blanks', () => {
	it("pages the tenant's blanks by name", async () => {
		const response = await send(fake, '/v1/blanks', asStore);

		expect(response.status).toBe(200);
		const body = await response.json();
		expect(body).toMatchObject({
			total: 3,
			page: 1,
			pageSize: 50,
			orderedFirst: false,
		});
		expect(body.blanks.map((b: { id: string }) => b.id)).toEqual([
			FIXTURE.blank.hoodie,
			FIXTURE.blank.tee,
			FIXTURE.blank.bare,
		]);
	});

	it("gives each blank its first view's photo, none for a blank without one", async () => {
		const response = await send(fake, '/v1/blanks', asStore);

		const body = await response.json();
		const thumbnails = Object.fromEntries(
			body.blanks.map((b: { id: string; thumbnailUrl: string | null }) => [
				b.id,
				b.thumbnailUrl,
			]),
		);
		expect(thumbnails[FIXTURE.blank.tee]).toMatch(/^http:\/\/fake\.test\/i\//);
		expect(thumbnails[FIXTURE.blank.bare]).toBeNull();
	});

	it('keeps the blanks with a placement when asked', async () => {
		const response = await send(fake, '/v1/blanks?hasPlacements=true', asStore);

		const body = await response.json();
		expect(body.total).toBe(2);
		expect(body.blanks.map((b: { id: string }) => b.id)).not.toContain(
			FIXTURE.blank.bare,
		);
	});

	it('keeps every blank for hasPlacements=false', async () => {
		const response = await send(
			fake,
			'/v1/blanks?hasPlacements=false',
			asStore,
		);

		expect((await response.json()).total).toBe(3);
	});

	it('keeps the blanks whose name holds q, case aside', async () => {
		const response = await send(fake, '/v1/blanks?q=TEE', asStore);

		const body = await response.json();
		expect(body.blanks.map((b: { id: string }) => b.id)).toEqual([
			FIXTURE.blank.tee,
		]);
	});

	it('serves the page asked for', async () => {
		const response = await send(fake, '/v1/blanks?page=2&pageSize=2', asStore);

		const body = await response.json();
		expect(body).toMatchObject({ total: 3, page: 2, pageSize: 2 });
		expect(body.blanks.map((b: { id: string }) => b.id)).toEqual([
			FIXTURE.blank.bare,
		]);
	});

	it("lists only the caller's tenant", async () => {
		const response = await send(fake, '/v1/blanks', asSandbox);

		expect(await response.json()).toMatchObject({ blanks: [], total: 0 });
	});
});

describe('GET /v1/blanks/{id}', () => {
	it('serves the blank with its placements and where each value comes from', async () => {
		const response = await send(
			fake,
			`/v1/blanks/${FIXTURE.blank.tee}`,
			asStore,
		);

		expect(response.status).toBe(200);
		const body = await response.json();
		expect(body).toMatchObject({
			id: FIXTURE.blank.tee,
			name: 'Fixture tee',
			axes: ['Color', 'Size'],
			copiedFrom: null,
			defaultPrintshopId: FIXTURE.printshop.main,
			partnerLocked: false,
			machineProfileId: null,
		});
		expect(body.placements[0]).toMatchObject({
			id: FIXTURE.placement.front,
			name: 'Front',
			maxWidthMm: 330,
			maxHeightMm: 420,
			defaultWidthMm: DEFAULT_BOX.widthMm,
			defaultHeightMm: DEFAULT_BOX.heightMm,
		});
		expect(body.placements[0].views).toEqual([
			{
				id: 'view_front',
				label: 'Front',
				photoSizePx: { widthPx: 1000, heightPx: 1000 },
				placementRectPx: { x: 250, y: 200, w: 500, h: 600 },
				photos: [
					{
						id: FIXTURE.mockup.front,
						assetId: `asset_${FIXTURE.blank.tee}_${FIXTURE.placement.front}`,
						variantRefs: [FIXTURE.variant.teeWhiteS, FIXTURE.variant.teeWhiteM],
					},
				],
			},
			expect.objectContaining({ label: 'Front, dark' }),
		]);
		expect(body.resolved).toMatchObject({
			defaultPrintshopId: { value: FIXTURE.printshop.main, source: 'own' },
			machineProfileId: { value: null, source: 'none' },
		});
		expect(body.resolved.placements[0]).toMatchObject({
			id: FIXTURE.placement.front,
			printedAs: 'Front',
			printedAsLinked: false,
			dpi: { value: null, source: 'none' },
		});
	});

	it("resolves a blank's printshop to its own, whatever the tenant's default", async () => {
		const response = await send(
			fake,
			`/v1/blanks/${FIXTURE.blank.hoodie}`,
			asStore,
		);

		const body = await response.json();
		expect(body.defaultPrintshopId).toBe(FIXTURE.printshop.other);
		expect(body.resolved.defaultPrintshopId).toEqual({
			value: FIXTURE.printshop.other,
			source: 'own',
		});
	});

	it.each([
		['an unknown blank', 'blank_missing', asStore],
		["another tenant's blank", FIXTURE.blank.tee, asSandbox],
	])('answers %s with not_found', async (_, id, as) => {
		const response = await send(fake, `/v1/blanks/${id}`, as);

		expect(response.status).toBe(404);
		expect(await response.json()).toEqual({ error: 'not_found' });
	});
});

describe('GET /v1/blanks/{id}/placements/{placementId}/fitting-templates', () => {
	const fitting = (
		blankId: string = FIXTURE.blank.tee,
		placementId: string = FIXTURE.placement.front,
		query = '',
	) =>
		`/v1/blanks/${blankId}/placements/${placementId}/fitting-templates${query}`;

	it('serves each live template at the size it prints at there', async () => {
		const response = await send(fake, fitting(), asStore);

		expect(response.status).toBe(200);
		expect(await response.json()).toEqual({
			items: [
				{
					templateId: FIXTURE.template.landscape,
					name: POSTER_NAME,
					status: 'live',
					ratio: { w: 420, h: 297 },
					ratioTolerance: 0.05,
					printWidthMm: POSTER_FITTED.widthMm,
					printHeightMm: POSTER_FITTED.heightMm,
					verdict: { legal: true },
					lowResolution: [],
					// A print is placed inside the fake's placements, so no size is named.
					sizes: { fits: [], misfits: [] },
					previewUrl: expect.stringMatching(/^http:\/\/fake\.test\/i\//),
				},
				{
					templateId: FIXTURE.template.live,
					name: TEE_NAME,
					status: 'live',
					ratio: { w: 280, h: 360 },
					ratioTolerance: 0,
					printWidthMm: TEE_FITTED.widthMm,
					printHeightMm: TEE_FITTED.heightMm,
					verdict: { legal: true },
					lowResolution: [],
					sizes: { fits: [], misfits: [] },
					previewUrl: expect.stringMatching(/^http:\/\/fake\.test\/i\//),
				},
				{
					templateId: FIXTURE.template.requireChange,
					name: TEE_NAME,
					status: 'live',
					ratio: { w: 280, h: 360 },
					ratioTolerance: 0,
					printWidthMm: TEE_FITTED.widthMm,
					printHeightMm: TEE_FITTED.heightMm,
					verdict: { legal: true },
					lowResolution: [],
					sizes: { fits: [], misfits: [] },
					previewUrl: expect.stringMatching(/^http:\/\/fake\.test\/i\//),
				},
			],
			total: 3,
			page: 1,
			pageSize: 50,
		});
	});

	it.each([
		[
			'all',
			['mini-poster@1', 'mini-tee@2', 'mini-tee-change@1', 'mini-tee-paused@1'],
		],
		['paused', ['mini-tee-paused@1']],
		['draft', []],
	])('filters by status=%s', async (status, ids) => {
		const response = await send(
			fake,
			fitting(undefined, undefined, `?status=${status}`),
			asStore,
		);

		const body = await response.json();
		expect(body.items.map((t: { templateId: string }) => t.templateId)).toEqual(
			ids,
		);
		expect(body.total).toBe(ids.length);
	});

	it('keeps the templates whose name holds q, and pages', async () => {
		const response = await send(
			fake,
			fitting(undefined, undefined, '?q=t-SHIRT&pageSize=1'),
			asStore,
		);

		const body = await response.json();
		expect(body).toMatchObject({ total: 2, page: 1, pageSize: 1 });
		expect(body.items[0].templateId).toBe(FIXTURE.template.live);
	});

	it('answers a placement whose stored sizes are unusable as not_found', async () => {
		Object.assign(placementOf(FIXTURE.blank.tee, FIXTURE.placement.front), {
			maxWidthMm: 0,
		});

		const response = await send(fake, fitting(), asStore);

		expect(response.status).toBe(404);
		expect(await response.json()).toEqual({ error: 'not_found' });
	});

	it.each([
		['an unknown placement', fitting(undefined, 'sleeve'), asStore],
		[
			'a blank without placements',
			fitting(FIXTURE.blank.bare, FIXTURE.placement.front),
			asStore,
		],
		["another tenant's blank", fitting(), asSandbox],
	])('answers %s with not_found', async (_, path, as) => {
		const response = await send(fake, path, as);

		expect(response.status).toBe(404);
		expect(await response.json()).toEqual({ error: 'not_found' });
	});
});

describe('GET /v1/printshop-connections', () => {
	it("lists the tenant's printshops, naming its default", async () => {
		const response = await send(fake, '/v1/printshop-connections', asStore);

		expect(response.status).toBe(200);
		expect(await response.json()).toEqual({
			connections: [
				{
					id: FIXTURE.printshop.main,
					name: 'Fixture Print AB',
					adapter: 'custom',
					active: true,
					isDefault: true,
					catalogue: false,
					asksOfDesigns: { commitKeys: [], needsBlank: false },
				},
				{
					id: FIXTURE.printshop.integrated,
					name: 'Gelato',
					adapter: 'gelato',
					active: true,
					isDefault: false,
					catalogue: true,
					asksOfDesigns: { commitKeys: [], needsBlank: true },
				},
				{
					id: FIXTURE.printshop.other,
					name: 'Other Print AB',
					adapter: 'custom',
					active: true,
					isDefault: false,
					catalogue: false,
					asksOfDesigns: { commitKeys: [], needsBlank: false },
				},
			],
		});
	});

	it('keeps the printshops whose name holds q, case aside', async () => {
		const response = await send(
			fake,
			'/v1/printshop-connections?q=other',
			asStore,
		);

		const body = await response.json();
		expect(body.connections.map((c: { id: string }) => c.id)).toEqual([
			FIXTURE.printshop.other,
		]);
	});

	it("lists only the caller's tenant", async () => {
		const response = await send(fake, '/v1/printshop-connections', asSandbox);

		expect(await response.json()).toEqual({ connections: [] });
	});
});

describe('POST /v1/templates/{id}/print-size', () => {
	const tee = templatePath(FIXTURE.template.live, '/print-size');
	const poster = templatePath(FIXTURE.template.landscape, '/print-size');
	const front = {
		blankId: FIXTURE.blank.tee,
		placementId: FIXTURE.placement.front,
	};
	/** Twice the fitted tee: its shape, beyond the placement's 330 × 420. */
	const tooLarge = { widthMm: 560, heightMm: 720 };
	/** The default box: inside the placement, not the tee's 280:360 shape. */
	const offShape = DEFAULT_BOX;

	const judge = (path: string, json: unknown, as = asStore) =>
		send(fake, path, { ...as, method: 'POST', json });

	it('calls a size of the declared shape legal', async () => {
		const response = await judge(tee, TEE_FITTED);

		expect(response.status).toBe(200);
		expect(await response.json()).toEqual({ legal: true, lowResolution: [] });
	});

	it('calls a size of another shape wrong_ratio at tolerance 0', async () => {
		const response = await judge(tee, offShape);

		expect(await response.json()).toEqual({
			legal: false,
			reason: 'wrong_ratio',
			lowResolution: [],
		});
	});

	it.each([
		[
			'inside',
			{ widthMm: 420, heightMm: 310 },
			{ legal: true, lowResolution: [] },
		],
		[
			'outside',
			{ widthMm: 420, heightMm: 320 },
			{ legal: false, reason: 'wrong_ratio', lowResolution: [] },
		],
	])('judges a shape %s the relative tolerance', async (_, size, verdict) => {
		const response = await judge(poster, size);

		expect(await response.json()).toEqual(verdict);
	});

	it("judges a size against the placement's maximum, too_large first", async () => {
		const response = await judge(tee, { ...tooLarge, ...front });

		expect(await response.json()).toEqual({
			legal: false,
			reason: 'too_large',
			lowResolution: [],
		});
	});

	it('leaves the bounds unchecked without a placement', async () => {
		const response = await judge(tee, tooLarge);

		expect(await response.json()).toEqual({ legal: true, lowResolution: [] });
	});

	it('answers one verdict per size, in the order asked', async () => {
		const response = await judge(tee, {
			sizes: [TEE_FITTED, tooLarge, offShape],
			...front,
		});

		expect(await response.json()).toEqual({
			verdicts: [
				{ legal: true, lowResolution: [] },
				{ legal: false, reason: 'too_large', lowResolution: [] },
				{ legal: false, reason: 'wrong_ratio', lowResolution: [] },
			],
		});
	});

	it('refuses a blank without a placement', async () => {
		const response = await judge(tee, {
			...TEE_FITTED,
			blankId: FIXTURE.blank.tee,
		});

		expect(response.status).toBe(422);
		expect(await response.json()).toEqual({
			error: 'invalid_body',
			reason: 'placementId: blankId and placementId must be given together',
		});
	});

	it.each([
		['an unknown placement', tee, { ...front, placementId: 'sleeve' }, asStore],
		[
			"another tenant's blank",
			templatePath(FIXTURE.template.sandbox, '/print-size'),
			front,
			asSandbox,
		],
	])('refuses %s as unknown_placement', async (_, path, named, as) => {
		const response = await judge(path, { ...TEE_FITTED, ...named }, as);

		expect(response.status).toBe(422);
		expect(await response.json()).toEqual({ error: 'unknown_placement' });
	});

	it.each([
		[
			'an unknown template',
			templatePath('mini-tee@99', '/print-size'),
			asStore,
		],
		[
			'a template its share link does not reach',
			templatePath(FIXTURE.template.previous, '/print-size'),
			{ headers: bearer(FIXTURE.shareLink) },
		],
	])('answers %s with not_found', async (_, path, as) => {
		const response = await judge(path, TEE_FITTED, as);

		expect(response.status).toBe(404);
		expect(await response.json()).toEqual({ error: 'not_found' });
	});

	it('answers a share link for the template it reaches', async () => {
		const response = await judge(tee, TEE_FITTED, {
			headers: bearer(FIXTURE.shareLink),
		});

		expect(await response.json()).toEqual({ legal: true, lowResolution: [] });
	});
});

describe('GET /v1/blanks/{id}/mockups', () => {
	const mockups = `/v1/blanks/${FIXTURE.blank.tee}/mockups`;

	it('lists each placement with its views, a mount for each finished photo', async () => {
		const response = await send(fake, mockups, asStore);

		expect(response.status).toBe(200);
		const body = await response.json();
		expect(body.placements.map((p: { id: string }) => p.id)).toEqual([
			FIXTURE.placement.front,
			FIXTURE.placement.back,
		]);
		expect(body.placements[0].views).toEqual([
			{
				id: 'view_front',
				label: 'Front',
				photoSizePx: { widthPx: 1000, heightPx: 1000 },
				placementRectPx: { x: 250, y: 200, w: 500, h: 600 },
				photos: [
					{
						id: FIXTURE.mockup.front,
						variantRefs: [FIXTURE.variant.teeWhiteS, FIXTURE.variant.teeWhiteM],
						thumbnailUrl: expect.stringMatching(/^http:\/\/fake\.test\/i\//),
						width: 1000,
						height: 1000,
						mount: {
							url: expect.stringMatching(/^http:\/\/fake\.test\/i\//),
							placementRectPx: { x: 250, y: 200, w: 500, h: 600 },
							photoSizePx: { w: 1000, h: 1000 },
						},
					},
				],
			},
			expect.objectContaining({
				label: 'Front, dark',
				photos: [expect.objectContaining({ id: FIXTURE.mockup.frontDark })],
			}),
		]);
		expect(body.placements[1].views[0].photos[1]).toMatchObject({
			id: FIXTURE.mockup.uploading,
			width: null,
			height: null,
			mount: null,
		});
	});

	it('mounts no photo of a view whose print rect is not marked', async () => {
		const [flat] = placementOf(
			FIXTURE.blank.tee,
			FIXTURE.placement.front,
		).views;
		if (flat !== undefined) {
			flat.placementRectPx = null;
		}

		const body = await (await send(fake, mockups, asStore)).json();

		expect(body.placements[0].views[0]).toMatchObject({
			placementRectPx: null,
			photos: [{ id: FIXTURE.mockup.front, mount: null }],
		});
	});

	it(
		'links photos at the origin a browser reaches the fake at',
		async () => {
			const behindProxy = createFake({ publicOrigin: 'http://localhost:8890' });

			const body = await (await send(behindProxy, mockups, asStore)).json();

			expect(body.placements[0].views[0].photos[0].mount.url).toMatch(
				/^http:\/\/localhost:8890\/i\//,
			);
			expect(behindProxy.violations).toEqual([]);
		},
		OWN_FAKE_TIMEOUT_MS,
	);

	it("answers not_found for another tenant's blank", async () => {
		const response = await send(fake, mockups, asSandbox);

		expect(response.status).toBe(404);
	});

	it('opens a mount photo for anyone, from any origin, until the mockup is gone', async () => {
		const body = await (await send(fake, mockups, asStore)).json();
		const url = new URL(body.placements[0].views[0].photos[0].mount.url);

		const photo = await send(fake, url.pathname, {
			headers: { origin: 'http://shop.test' },
		});

		expect(photo.status).toBe(200);
		expect(photo.headers.get('content-type')).toBe('image/jpeg');
		expect(photo.headers.get('access-control-allow-origin')).toBe('*');

		placementOf(FIXTURE.blank.tee, FIXTURE.placement.front).views = [];
		expect((await send(fake, url.pathname)).status).toBe(404);
	});
});

describe('GET /v1/blanks/{id}/variants', () => {
	const variants = (blankId: string) => `/v1/blanks/${blankId}/variants`;

	it('lists every variant, retired ones too, with the views it has a photo in', async () => {
		const response = await send(fake, variants(FIXTURE.blank.tee), asStore);

		expect(response.status).toBe(200);
		const body = await response.json();
		expect(body.axes).toEqual(['Color', 'Size']);
		const [color, size] = body.options;
		expect(color.appearances).toMatchObject({
			White: { kind: 'fill', colours: ['#ffffff'] },
			Black: { kind: 'fill', colours: ['#000000'] },
		});
		expect(Object.keys(color.appearances)).toEqual(color.values);
		expect(size.appearances.S).toBeNull();
		const byRef = new Map(
			body.variants.map((v: { ref: string }) => [v.ref, v]),
		);
		const back = {
			placementId: FIXTURE.placement.back,
			viewId: 'view_back',
			label: 'Back',
		};
		expect(byRef.get(FIXTURE.variant.teeWhiteS)).toEqual({
			ref: FIXTURE.variant.teeWhiteS,
			options: { Color: 'White', Size: 'S' },
			retired: null,
			views: [
				{
					placementId: FIXTURE.placement.front,
					viewId: 'view_front',
					label: 'Front',
				},
				back,
			],
		});
		expect(byRef.get(FIXTURE.variant.teeBlackM)).toMatchObject({
			views: [
				{
					placementId: FIXTURE.placement.front,
					viewId: 'view_front_dark',
					label: 'Front, dark',
				},
				back,
			],
		});
		expect(byRef.get(FIXTURE.variant.teeRedS)).toMatchObject({
			retired: { reason: 'no_longer_sold' },
			views: [],
		});
		expect(byRef.get(FIXTURE.variant.teeRedM)).toMatchObject({
			retired: { reason: 'retired' },
		});
		expect(byRef.get(FIXTURE.variant.teeHeatherS)).toMatchObject({
			retired: null,
			views: [],
		});
	});

	it('lists the one variant of a blank with no options', async () => {
		const response = await send(fake, variants(FIXTURE.blank.bare), asStore);

		expect(await response.json()).toEqual({
			axes: [],
			options: [],
			areasVaryBy: [],
			paintBy: null,
			variants: [
				{ ref: FIXTURE.variant.tote, options: {}, retired: null, views: [] },
			],
		});
	});

	it("answers not_found for another tenant's blank", async () => {
		const response = await send(fake, variants(FIXTURE.blank.tee), asSandbox);

		expect(response.status).toBe(404);
	});
});

describe('a blank read by the storefront', () => {
	const paths = {
		mockups: `/v1/blanks/${FIXTURE.blank.tee}/mockups`,
		variants: `/v1/blanks/${FIXTURE.blank.tee}/variants`,
	};

	it.each(Object.entries(paths))(
		'serves the %s to the publishable key as to the integration key',
		async (_, path) => {
			const browser = await send(fake, path, asStorefront);
			const server = await send(fake, path, asStore);

			expect(browser.status).toBe(200);
			expect(await browser.json()).toEqual(await server.json());
		},
	);

	it.each(Object.entries(paths))(
		'refuses the %s to the publishable key from another origin, or none',
		async (_, path) => {
			const foreign = await send(fake, path, {
				headers: { ...bearer(MAIN.publishableKey), origin: FOREIGN_ORIGIN },
			});
			const originless = await send(fake, path, {
				headers: bearer(MAIN.publishableKey),
			});

			expect(foreign.status).toBe(403);
			expect(await foreign.json()).toEqual({ error: 'origin_forbidden' });
			expect(originless.status).toBe(403);
		},
	);

	it("answers not_found for another tenant's blank", async () => {
		const response = await send(fake, paths.variants, {
			headers: {
				...bearer(FIXTURE.store.sandbox.publishableKey),
				origin: SITE_ORIGIN,
			},
		});

		expect(response.status).toBe(404);
	});

	it('opens the mount photo a publishable read links to, with no key', async () => {
		const body = await (await send(fake, paths.mockups, asStorefront)).json();
		const url = new URL(body.placements[0].views[0].photos[0].mount.url);

		const photo = await send(fake, url.pathname, {
			headers: { origin: SITE_ORIGIN },
		});

		expect(photo.status).toBe(200);
		expect(photo.headers.get('content-type')).toBe('image/jpeg');
	});

	it.each([FIXTURE.blank.tee, FIXTURE.blank.hoodie])(
		'names in each photo of %s exactly the variants whose views hold it',
		async (blankId) => {
			const mockups = await (
				await send(fake, `/v1/blanks/${blankId}/mockups`, asStorefront)
			).json();
			const { variants } = await (
				await send(fake, `/v1/blanks/${blankId}/variants`, asStorefront)
			).json();
			type Photo = { variantRefs: string[] };
			type View = { id: string; photos: Photo[] };
			const viewsShowing = (ref: string) =>
				mockups.placements.flatMap((p: { id: string; views: View[] }) =>
					p.views
						.filter((v) =>
							v.photos.some((photo) => photo.variantRefs.includes(ref)),
						)
						.map((v) => `${p.id}/${v.id}`),
				);
			const known = new Set(variants.map((v: { ref: string }) => v.ref));

			for (const variant of variants as {
				ref: string;
				views: { placementId: string; viewId: string }[];
			}[]) {
				expect(
					variant.views.map((v) => `${v.placementId}/${v.viewId}`),
				).toEqual(viewsShowing(variant.ref));
			}
			const named = mockups.placements.flatMap((p: { views: View[] }) =>
				p.views.flatMap((v) => v.photos.flatMap((photo) => photo.variantRefs)),
			);
			expect(named.filter((ref: string) => !known.has(ref))).toEqual([]);
		},
	);

	it('shows a colour in some views only, and one in none', async () => {
		const { variants } = await (
			await send(fake, paths.variants, asStorefront)
		).json();
		const viewsOf = (ref: string) =>
			variants
				.find((v: { ref: string }) => v.ref === ref)
				.views.map((v: { viewId: string }) => v.viewId);

		expect(viewsOf(FIXTURE.variant.teeWhiteS)).toEqual([
			'view_front',
			'view_back',
		]);
		expect(viewsOf(FIXTURE.variant.teeBlackS)).toEqual([
			'view_front_dark',
			'view_back',
		]);
		expect(viewsOf(FIXTURE.variant.teeHeatherS)).toEqual([]);
	});
});
