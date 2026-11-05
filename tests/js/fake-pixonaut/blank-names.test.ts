import { afterEach, beforeEach, describe, expect, it } from 'vitest';
import { createFake } from '../../fake-pixonaut/src/app';
import { FIXTURE } from '../../fake-pixonaut/src/fixture-ids';
import { bearer, MAIN, SITE_URL, send } from './support';

const fake = createFake();
const asStore = { headers: bearer(MAIN.integrationKey) };
const tee = `/v1/blanks/${FIXTURE.blank.tee}`;
const hoodie = `/v1/blanks/${FIXTURE.blank.hoodie}`;

type Named = { id: string; label: string };
type VariantRead = { ref: string; options: object; views: Named[] };
const read = async (path: string) => {
	const response = await send(fake, path, asStore);
	expect(response.status).toBe(200);
	return response.json();
};

/** Each view's label by id, across every placement of a blank answer. */
const viewLabels = (body: {
	placements: { views: Named[] }[];
}): Record<string, string> =>
	Object.fromEntries(
		body.placements.flatMap((p) => p.views.map((v) => [v.id, v.label])),
	);

beforeEach(() => {
	fake.reset('default', SITE_URL);
});

afterEach(() => {
	expect(fake.violations).toEqual([]);
});

describe('a blank read in a language', () => {
	it('names the options and views in it where the blank has a name, else as written', async () => {
		const body = await read(`${tee}/variants?lang=sv`);

		expect(body.axes).toEqual(['Färg', 'Storlek']);
		expect(body.options[0]).toMatchObject({
			name: 'Färg',
			values: ['Vit', 'Svart', 'Röd', 'Heather'],
		});
		expect(body.options[0].appearances.Vit).toEqual({
			kind: 'fill',
			colours: ['#ffffff'],
		});
		const white = body.variants.find(
			(v: VariantRead) => v.ref === FIXTURE.variant.teeWhiteS,
		);
		expect(white.options).toEqual({ Färg: 'Vit', Storlek: 'S' });
		expect(white.views.map((v: Named) => v.label)).toEqual([
			'Framsida',
			'Baksida',
		]);
	});

	it('answers the names as written without a language', async () => {
		const body = await read(`${tee}/variants`);

		expect(body.axes).toEqual(['Color', 'Size']);
		expect(
			body.variants.find(
				(v: VariantRead) => v.ref === FIXTURE.variant.teeWhiteS,
			).options,
		).toEqual({ Color: 'White', Size: 'S' });
	});

	it('answers the names as written in a language the blank has none in', async () => {
		expect((await read(`${tee}/variants?lang=de`)).axes).toEqual([
			'Color',
			'Size',
		]);
		expect((await read(`${hoodie}/variants?lang=sv`)).axes).toEqual([
			'Color',
			'Size',
		]);
	});

	it('labels the views of the mockups in it', async () => {
		expect(viewLabels(await read(`${tee}/mockups?lang=sv`))).toEqual({
			view_front: 'Framsida',
			view_front_dark: 'Front, dark',
			view_back: 'Baksida',
		});
	});

	it('answers the blank itself as written', async () => {
		const blank = await read(tee);
		expect(blank.axes).toEqual(['Color', 'Size']);
		expect(viewLabels(blank)).toMatchObject({ view_front: 'Front' });
	});

	it('refuses a language Pixonaut does not speak', async () => {
		const response = await send(fake, `${tee}/variants?lang=xx`, asStore);

		expect(response.status).not.toBe(200);
		expect(fake.violations).toMatchObject([{ at: 'query.lang' }]);
		fake.violations.length = 0;
	});
});
