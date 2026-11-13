import { afterEach, beforeEach, describe, expect, it } from 'vitest';
import { createFake } from '../../fake-pixonaut/src/app';
import { FIXTURE } from '../../fake-pixonaut/src/fixture-ids';
import { bearer, MAIN, SITE_URL, send, templatePath } from './support';

const fake = createFake();
const asStore = { headers: bearer(MAIN.integrationKey) };

const LIVE_BASE = 'mini-tee';
const LIVE_VERSION = 2;
const PAUSED_BASE = 'mini-tee-paused';

function control(path: string, json: unknown) {
	return send(fake, `/__fake/${path}`, { method: 'POST', json });
}

async function changed(path: string, json: unknown): Promise<void> {
	const response = await control(path, json);
	expect(response.status, await response.clone().text()).toBe(200);
}

async function pulled(
	key: string = MAIN.integrationKey,
): Promise<{ type: string; payload: unknown }[]> {
	const response = await send(fake, '/v1/events', { headers: bearer(key) });
	expect(response.status).toBe(200);
	return (await response.json()).events;
}

/** The live templates a key lists, as base id and latest version. */
async function liveTemplates(): Promise<Record<string, number>> {
	const response = await send(fake, '/v1/templates?status=live', asStore);
	expect(response.status).toBe(200);
	const { items } = await response.json();
	return Object.fromEntries(
		items.map((t: { baseId: string; latestVersion: number }) => [
			t.baseId,
			t.latestVersion,
		]),
	);
}

/** The ids of the blanks a key lists. */
async function listedBlanks(): Promise<string[]> {
	const response = await send(fake, '/v1/blanks', asStore);
	expect(response.status).toBe(200);
	return (await response.json()).blanks.map((b: { id: string }) => b.id);
}

beforeEach(async () => {
	fake.reset('default', SITE_URL);
	// The stream is the subject here, not the deliveries: none is sent.
	await control('webhooks/drop', { store: MAIN.id, drop: true });
});

afterEach(() => {
	expect(fake.violations).toEqual([]);
});

describe('template changes', () => {
	it("publishes a template's next version", async () => {
		await changed('templates/publish', { template: LIVE_BASE });

		const next = `${LIVE_BASE}@${LIVE_VERSION + 1}`;
		expect(await pulled()).toEqual([
			expect.objectContaining({
				type: 'template.published',
				payload: { templateId: next, version: LIVE_VERSION + 1 },
			}),
		]);
		expect((await liveTemplates())[LIVE_BASE]).toBe(LIVE_VERSION + 1);
		expect((await send(fake, templatePath(next), asStore)).status).toBe(200);
	});

	it('pauses a template and resumes it', async () => {
		await changed('templates/pause', { template: LIVE_BASE });

		expect(await liveTemplates()).not.toHaveProperty(LIVE_BASE);

		await changed('templates/resume', { template: LIVE_BASE });

		expect((await liveTemplates())[LIVE_BASE]).toBe(LIVE_VERSION);
		expect(await pulled()).toMatchObject([
			{
				type: 'template.live_changed',
				payload: { baseId: LIVE_BASE, live: false },
			},
			{
				type: 'template.live_changed',
				payload: { baseId: LIVE_BASE, live: true },
			},
		]);
	});

	it('refuses to pause a paused template, as only a flip emits', async () => {
		expect(
			(await control('templates/pause', { template: PAUSED_BASE })).status,
		).toBe(400);
		expect(await pulled()).toEqual([]);
	});

	it('deletes every version of a template', async () => {
		await changed('templates/delete', { template: LIVE_BASE });

		expect(await liveTemplates()).not.toHaveProperty(LIVE_BASE);
		expect(
			(await send(fake, templatePath(FIXTURE.template.live), asStore)).status,
		).toBe(404);
		expect(await pulled()).toMatchObject([
			{
				type: 'template.deleted',
				payload: { baseId: LIVE_BASE, wasLive: true },
			},
		]);
	});

	it("changes a template's id, the old one still reading it", async () => {
		const newId = 'mini-tee-pandas';
		await changed('templates/rename', { template: LIVE_BASE, newId });

		expect(await pulled()).toMatchObject([
			{
				type: 'template.renamed',
				payload: { oldId: LIVE_BASE, newId, renamedAt: expect.any(Number) },
			},
		]);
		expect(await liveTemplates()).toMatchObject({ [newId]: LIVE_VERSION });
		expect(await liveTemplates()).not.toHaveProperty(LIVE_BASE);
		for (const id of [FIXTURE.template.live, `${newId}@${LIVE_VERSION}`]) {
			const read = await send(fake, templatePath(id), asStore);
			expect(read.status).toBe(200);
			expect((await read.json()).template.id).toBe(newId);
		}
	});

	it("refuses an id another template has, or none, and answers a pull asking for the rename's type", async () => {
		for (const newId of [PAUSED_BASE, undefined, `${LIVE_BASE}-2@1`]) {
			expect(
				(await control('templates/rename', { template: LIVE_BASE, newId }))
					.status,
			).toBe(400);
		}
		const asked = await send(fake, '/v1/events?types=template.renamed', {
			headers: bearer(MAIN.integrationKey),
		});
		expect(asked.status).toBe(200);
		expect(await asked.json()).toMatchObject({ events: [] });
	});

	it('refuses a template it does not have', async () => {
		for (const body of [{}, { template: 'nope' }]) {
			expect((await control('templates/publish', body)).status).toBe(400);
		}
		expect(await pulled()).toEqual([]);
	});
});

describe('blank changes', () => {
	const blankPath = `/v1/blanks/${FIXTURE.blank.tee}`;

	it('updates a blank, removing a placement', async () => {
		await changed('blanks/update', {
			blank: FIXTURE.blank.tee,
			removePlacement: FIXTURE.placement.back,
		});

		const blank = await (await send(fake, blankPath, asStore)).json();
		expect(blank.placements.map((p: { id: string }) => p.id)).toEqual([
			FIXTURE.placement.front,
		]);
		expect(await pulled()).toMatchObject([
			{
				type: 'blank.updated',
				payload: { blankId: FIXTURE.blank.tee, fields: ['placements'] },
			},
		]);
	});

	it('retires a variant and sells it again', async () => {
		const variant = FIXTURE.variant.teeWhiteS;
		const retiredOf = async () => {
			const body = await (
				await send(fake, `${blankPath}/variants`, asStore)
			).json();
			return body.variants.find((v: { ref: string }) => v.ref === variant)
				.retired;
		};

		await changed('blanks/update', {
			blank: FIXTURE.blank.tee,
			variant,
			retired: 'retired',
		});
		expect(await retiredOf()).toEqual({ reason: 'retired' });
		await changed('blanks/update', {
			blank: FIXTURE.blank.tee,
			variant,
			retired: null,
		});

		expect(await retiredOf()).toBeNull();
		expect(await pulled()).toMatchObject([
			{
				type: 'blank.updated',
				payload: { blankId: FIXTURE.blank.tee, fields: ['variants'] },
			},
			{ type: 'blank.updated' },
		]);
	});

	it.each([
		['a variant the blank does not have', 'v_nope', 'retired'],
		['an unknown reason', FIXTURE.variant.teeWhiteS, 'discontinued'],
	])('refuses to retire %s', async (_, variant, retired) => {
		const response = await control('blanks/update', {
			blank: FIXTURE.blank.tee,
			variant,
			retired,
		});

		expect(response.status).toBe(400);
		expect(await pulled()).toEqual([]);
	});

	it('refuses to remove a placement the blank does not have', async () => {
		const response = await control('blanks/update', {
			blank: FIXTURE.blank.tee,
			removePlacement: 'sleeve',
		});

		expect(response.status).toBe(400);
		expect(await pulled()).toEqual([]);
	});

	it('deletes a blank, still read by id and left out of the list', async () => {
		await changed('blanks/delete', { blank: FIXTURE.blank.tee });

		const read = await send(fake, blankPath, asStore);
		expect(read.status).toBe(200);
		expect((await read.json()).deleted).toEqual({
			at: expect.any(Number),
			by: null,
		});
		expect(await listedBlanks()).not.toContain(FIXTURE.blank.tee);
		expect(await pulled()).toMatchObject([
			{
				type: 'blank.deleted',
				payload: { blankId: FIXTURE.blank.tee },
			},
		]);
		expect(
			(await control('blanks/delete', { blank: FIXTURE.blank.tee })).status,
		).toBe(400);
	});

	it('restores a deleted blank', async () => {
		expect(
			(await control('blanks/restore', { blank: FIXTURE.blank.tee })).status,
		).toBe(400);
		await changed('blanks/delete', { blank: FIXTURE.blank.tee });

		await changed('blanks/restore', { blank: FIXTURE.blank.tee });

		expect(
			(await (await send(fake, blankPath, asStore)).json()).deleted,
		).toBeNull();
		expect(await listedBlanks()).toContain(FIXTURE.blank.tee);
		expect(await pulled()).toMatchObject([
			{ type: 'blank.deleted' },
			{
				type: 'blank.restored',
				payload: { blankId: FIXTURE.blank.tee, blankName: 'Fixture tee' },
			},
		]);
	});

	it("emits to the store it names, the main one's stream untouched", async () => {
		await control('webhooks/drop', {
			store: FIXTURE.store.other.id,
			drop: true,
		});

		await changed('blanks/delete', {
			store: FIXTURE.store.other.id,
			blank: FIXTURE.blank.hoodie,
		});

		expect(await pulled()).toEqual([]);
		expect(await pulled(FIXTURE.store.other.integrationKey)).toMatchObject([
			{ type: 'blank.deleted' },
		]);
	});
});
