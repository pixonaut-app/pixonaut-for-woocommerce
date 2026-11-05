import { beforeEach, describe, expect, it } from 'vitest';
import { createFake } from '../../fake-pixonaut/src/app';
import { FIXTURE } from '../../fake-pixonaut/src/fixture-ids';
import { TEE_SHOWCASES } from '../../fake-pixonaut/src/fixtures';
import {
	bearer,
	MAIN,
	SITE_ORIGIN,
	SITE_URL,
	send,
	templatePath,
} from './support';

const fake = createFake();
const asStore = { headers: bearer(MAIN.integrationKey) };

beforeEach(() => {
	fake.reset('default', SITE_URL);
});

describe('GET /v1/templates/{id}', () => {
	it('serves the published document, its form and the tenant defaults', async () => {
		const response = await send(
			fake,
			templatePath(FIXTURE.template.live),
			asStore,
		);

		expect(response.status).toBe(200);
		const body = await response.json();
		expect(body.template).toMatchObject({ id: 'mini-tee', version: 2 });
		expect(body.formSchema.steps.length).toBeGreaterThan(0);
		expect(body).toMatchObject({
			printMargins: { bleedMm: 3, safeAreaMm: 5 },
			printFloorDpi: 150,
		});
	});

	it("answers the workspace's default language", async () => {
		const tenant = fake.state.tenants.get(FIXTURE.tenant.live);
		if (tenant === undefined) {
			throw new Error('The fixture has no live tenant.');
		}
		tenant.workspace.defaultLanguage = 'da';

		const response = await send(
			fake,
			templatePath(FIXTURE.template.live),
			asStore,
		);

		expect((await response.json()).workspaceLanguage).toBe('da');
	});

	it('serves a paused template version too', async () => {
		const response = await send(
			fake,
			templatePath(FIXTURE.template.paused),
			asStore,
		);

		expect(response.status).toBe(200);
		expect(fake.state.templates.get(FIXTURE.template.paused)?.status).toBe(
			'paused',
		);
	});

	it('answers an unknown version with a bare 404', async () => {
		const response = await send(fake, templatePath('mini-tee@99'), asStore);

		expect(response.status).toBe(404);
		expect(fake.violations).toEqual([]);
	});
});

describe('GET /v1/templates/{id}/constraints', () => {
	const constraints = templatePath(FIXTURE.template.live, '/constraints');

	it('serves the ratio, tolerance and fit', async () => {
		const response = await send(fake, constraints, asStore);

		expect(await response.json()).toEqual({
			templateId: FIXTURE.template.live,
			ratio: { w: 280, h: 360 },
			ratioTolerance: 0,
			fit: 'contain',
		});
	});

	it("adds a placement's bounds and the box a sizeless proof prints at", async () => {
		const response = await send(
			fake,
			`${constraints}?blankId=${FIXTURE.blank.tee}&placementId=${FIXTURE.placement.back}`,
			asStore,
		);

		expect((await response.json()).placement).toEqual({
			blankId: FIXTURE.blank.tee,
			placementId: FIXTURE.placement.back,
			maxWidthMm: 330,
			maxHeightMm: 420,
			defaultWidthMm: 300,
			defaultHeightMm: 360,
			coverage: 'within',
			// Its print area does not change with an option: the template fits every size.
			sizes: { fits: [], misfits: [] },
			printWidthMm: 280,
			printHeightMm: 360,
			bleedMm: 3,
			safeAreaMm: 5,
			cmykPreviewProfile: 'ISOcoated_v2_eci',
		});
	});

	it('refuses a placement that does not resolve', async () => {
		const response = await send(
			fake,
			`${constraints}?blankId=${FIXTURE.blank.tee}&placementId=sleeve`,
			asStore,
		);

		expect(response.status).toBe(422);
		expect(await response.json()).toEqual({ error: 'unknown_placement' });
	});

	it.each([
		['a zero maximum', { maxWidthMm: 0 }],
		['a non-finite default', { defaultHeightMm: Number.POSITIVE_INFINITY }],
		['a default above the maximum', { defaultWidthMm: 400 }],
	])('refuses a placement with %s', async (_, change) => {
		const back = fake.state.blanks
			.get(FIXTURE.blank.tee)
			?.placements.find((p) => p.id === FIXTURE.placement.back);
		Object.assign(back ?? {}, change);

		const response = await send(
			fake,
			`${constraints}?blankId=${FIXTURE.blank.tee}&placementId=${FIXTURE.placement.back}`,
			asStore,
		);

		expect(response.status).toBe(422);
		expect(await response.json()).toEqual({ error: 'unknown_placement' });
	});

	it('refuses a blank without a placement', async () => {
		const response = await send(
			fake,
			`${constraints}?blankId=${FIXTURE.blank.tee}`,
			asStore,
		);

		expect(response.status).toBe(422);
		expect(await response.json()).toEqual({ error: 'invalid_query' });
	});

	it('answers an unknown template with not_found', async () => {
		const response = await send(
			fake,
			templatePath('mini-tee@99', '/constraints'),
			asStore,
		);

		expect(response.status).toBe(404);
		expect(await response.json()).toEqual({ error: 'not_found' });
	});
});

describe('fixture set', () => {
	it('gives every template version and tenant its own copy', () => {
		const { templates, tenants } = fake.state;
		const live = templates.get(FIXTURE.template.live);
		const layer = live?.template.layers[0];
		if (layer === undefined) {
			throw new Error('The live fixture has no layer.');
		}
		const untouched = structuredClone(
			templates.get(FIXTURE.template.previous)?.template.layers[0],
		);
		layer.id = 'changed';
		const margins = tenants.get(FIXTURE.tenant.live)?.printMargins;
		Object.assign(margins ?? {}, { bleedMm: 0 });

		expect(
			templates.get(FIXTURE.template.previous)?.template.layers[0],
		).toEqual(untouched);
		expect(
			templates.get(FIXTURE.template.sandbox)?.template.layers[0]?.id,
		).not.toBe('changed');
		expect(tenants.get(FIXTURE.tenant.sandbox)?.printMargins.bleedMm).toBe(3);
	});
});

describe('showcase sets', () => {
	const asStorefront = {
		headers: { ...bearer(MAIN.publishableKey), origin: SITE_ORIGIN },
	};
	const previewPath = (id: string, query: string) =>
		`/t/${encodeURIComponent(id)}/preview.png?${query}`;

	it('lists a template version’s sets, and none for one without', async () => {
		const withSets = await send(
			fake,
			templatePath(FIXTURE.template.live),
			asStore,
		);
		const without = await send(
			fake,
			templatePath(FIXTURE.template.previous),
			asStore,
		);

		const sets: { id: string; name: string; previewUrl: string }[] = (
			await withSets.json()
		).showcases;
		expect(sets.map(({ id, name }) => ({ id, name }))).toEqual(TEE_SHOWCASES);
		expect((await without.json()).showcases).toEqual([]);
		for (const set of sets) {
			const preview = await send(fake, new URL(set.previewUrl).pathname);
			expect(preview.status).toBe(200);
		}
		expect(new Set(sets.map((s) => s.previewUrl)).size).toBe(sets.length);
		expect(fake.violations).toEqual([]);
	});

	it('draws the template’s preview with one of its sets', async () => {
		const [showcase] = TEE_SHOWCASES;

		const response = await send(
			fake,
			previewPath(FIXTURE.template.live, `size=320&showcase=${showcase?.id}`),
			asStorefront,
		);

		expect(response.status).toBe(200);
		expect(response.headers.get('content-type')).toBe('image/png');
		expect(fake.violations).toEqual([]);
	});

	it('answers a template it does not have with its router’s plain-text 404', async () => {
		const response = await send(
			fake,
			previewPath('mini-tee@99', 'size=320'),
			asStorefront,
		);

		expect(response.status).toBe(404);
		expect(response.headers.get('content-type')).toMatch(/^text\/plain/);
		expect(fake.violations).toEqual([]);
	});

	it('answers a set the template does not have with 404 showcase_not_found', async () => {
		const response = await send(
			fake,
			previewPath(FIXTURE.template.previous, 'showcase=showcase-anna'),
			asStorefront,
		);

		expect(response.status).toBe(404);
		expect(await response.json()).toEqual({ error: 'showcase_not_found' });
		expect(fake.violations).toEqual([]);
	});
});
