import { afterEach, beforeEach, describe, expect, it } from 'vitest';
import { createFake } from '../../fake-pixonaut/src/app';
import { FIXTURE } from '../../fake-pixonaut/src/fixture-ids';
import { bearer, MAIN, SITE_URL, send } from './support';

const fake = createFake();
const asStore = { headers: bearer(MAIN.integrationKey) };
const asSandbox = { headers: bearer(FIXTURE.store.sandbox.integrationKey) };

const GELATO = FIXTURE.printshop.integrated;
const POSTER = FIXTURE.catalogue.poster;

function products(connection: string, query = '') {
	return send(
		fake,
		`/v1/printshop-catalogue-products?connectionId=${connection}${query}`,
		asStore,
	);
}

function importPoster(json: unknown = undefined, product: string = POSTER) {
	return send(
		fake,
		`/v1/printshop-catalogue-products/${product}/blank?connectionId=${GELATO}`,
		{ ...asStore, method: 'POST', json },
	);
}

function control(path: string, json: unknown) {
	return send(fake, `/__fake/${path}`, { method: 'POST', json });
}

beforeEach(async () => {
	fake.reset('default', SITE_URL);
	await control('webhooks/drop', { store: MAIN.id, drop: true });
});

afterEach(() => {
	expect(fake.violations).toEqual([]);
});

describe('GET /v1/printshop-catalogue-products', () => {
	it("lists the printshop's products by name", async () => {
		const response = await products(GELATO, '&search=post');

		expect(response.status).toBe(200);
		expect(await response.json()).toMatchObject({
			total: 1,
			catalogueTotal: 2,
			products: [
				{
					productId: POSTER,
					name: 'Classic poster',
					holds: { variants: 3 },
					hasImportedBlank: false,
					importedBlankId: null,
				},
			],
		});
	});

	it('marks a product with the blank it was imported as', async () => {
		const imported = await (await importPoster()).json();

		const listed = await (await products(GELATO)).json();

		expect(
			listed.products.find(
				(p: { productId: string }) => p.productId === POSTER,
			),
		).toMatchObject({
			hasImportedBlank: true,
			importedBlankId: imported.blank.id,
		});
	});

	it.each([
		['a printshop without a catalogue', FIXTURE.printshop.main, 409],
		['no such printshop', 'ps_nope', 404],
	])('refuses %s', async (_, connection, status) => {
		expect((await products(connection)).status).toBe(status);
	});

	it("refuses another workspace's printshop", async () => {
		const response = await send(
			fake,
			`/v1/printshop-catalogue-products?connectionId=${GELATO}`,
			asSandbox,
		);

		expect(response.status).toBe(404);
	});
});

describe('POST /v1/printshop-catalogue-products/{productId}/blank', () => {
	it('makes a ready blank of the product, its sizes its variants', async () => {
		const response = await importPoster();

		expect(response.status).toBe(202);
		const { blank } = await response.json();
		expect(blank).toMatchObject({ name: 'Classic poster', status: 'ready' });
		const variants = await (
			await send(fake, `/v1/blanks/${blank.id}/variants`, asStore)
		).json();
		expect(variants.variants.map((v: { ref: string }) => v.ref)).toEqual([
			FIXTURE.variant.poster50x70,
			FIXTURE.variant.poster70x100,
			FIXTURE.variant.poster30x40,
		]);
	});

	it('keeps a held import importing until the control API finishes it', async () => {
		await control('catalogue/imports', { held: true });
		const { blank } = await (await importPoster()).json();

		expect(blank.status).toBe('importing');

		const finished = await control('blanks/finish-import', { blank: blank.id });

		expect(finished.status).toBe(200);
		const read = await (
			await send(fake, `/v1/blanks/${blank.id}`, asStore)
		).json();
		expect(read).toMatchObject({ status: 'ready', areasVaryBy: ['Size'] });
		const events = await (await send(fake, '/v1/events', asStore)).json();
		expect(events.events).toEqual([
			expect.objectContaining({
				type: 'blank.updated',
				payload: {
					blankId: blank.id,
					fields: ['status', 'placements', 'variants'],
				},
			}),
		]);
	});

	it('refuses a second import under the same name', async () => {
		await importPoster();

		const again = await importPoster();

		expect(again.status).toBe(409);
		expect(await again.json()).toEqual({ error: 'duplicate_name' });
	});

	it('refuses a product with nothing to print on', async () => {
		const response = await importPoster(
			undefined,
			FIXTURE.catalogue.unprintable,
		);

		expect(response.status).toBe(422);
		expect(await response.json()).toEqual({ error: 'no_printable_placement' });
	});
});
