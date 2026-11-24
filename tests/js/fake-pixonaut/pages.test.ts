import { afterEach, beforeEach, describe, expect, it } from 'vitest';
import { createFake } from '../../fake-pixonaut/src/app';
import { FIXTURE } from '../../fake-pixonaut/src/fixture-ids';
import { findProof } from '../../fake-pixonaut/src/proofs';
import { storefront } from './storefront-support';
import { MAIN, SITE_URL, send } from './support';

const fake = createFake();

const PAGES = { min: 2, max: 6, step: 2 };

function setPages(json: unknown, blankId: string = FIXTURE.blank.tee) {
	return send(fake, `/__fake/blanks/${blankId}/pages`, {
		method: 'POST',
		json,
	});
}

function proof(json: object, placementId: string = FIXTURE.placement.front) {
	return send(fake, '/v1/proofs', {
		method: 'POST',
		headers: storefront(),
		json: {
			templateId: FIXTURE.template.live,
			values: {},
			blankId: FIXTURE.blank.tee,
			placementId,
			...json,
		},
	});
}

beforeEach(async () => {
	fake.reset('default', SITE_URL);
	await setPages({ placement: FIXTURE.placement.front, ...PAGES });
});

afterEach(() => {
	expect(fake.violations).toEqual([]);
});

describe('POST /__fake/blanks/:id/pages', () => {
	it('prints the placement as pages', async () => {
		const read = await send(fake, `/v1/blanks/${FIXTURE.blank.tee}/mockups`, {
			headers: storefront(),
		});
		const { placements } = await read.json();

		const pages = Object.fromEntries(
			placements.map((placement: { id: string; pages?: object }) => [
				placement.id,
				placement.pages,
			]),
		);
		expect(pages[FIXTURE.placement.front]).toEqual(PAGES);
		expect(pages[FIXTURE.placement.back]).toBeUndefined();
	});

	it('refuses what is no placement or no range', async () => {
		expect((await setPages({ placement: 'nowhere', ...PAGES })).status).toBe(
			404,
		);
		expect(
			(
				await setPages({
					placement: FIXTURE.placement.front,
					min: 6,
					max: 2,
					step: 2,
				})
			).status,
		).toBe(400);
	});
});

describe('POST /v1/proofs of a page', () => {
	it('keeps the page and the pages of the item', async () => {
		const made = await proof({ page: 2, pageCount: 4 });

		expect(made.status).toBe(201);
		expect(
			findProof(fake.state, MAIN.id, (await made.json()).proofToken)?.page,
		).toEqual({ page: 2, pageCount: 4 });
	});

	it('refuses a page that does not fit its placement', async () => {
		const refused = async (made: Promise<Response>) => {
			const response = await made;
			return [response.status, (await response.json()).error];
		};

		expect(await refused(proof({}))).toEqual([422, 'page_required']);
		expect(await refused(proof({ page: 1, pageCount: 8 }))).toEqual([
			422,
			'page_count_out_of_range',
		]);
		expect(
			await refused(proof({ page: 1, pageCount: 4 }, FIXTURE.placement.back)),
		).toEqual([422, 'page_on_unpaged_placement']);
	});

	it('takes the pages of the item on another placement', async () => {
		expect((await proof({ pageCount: 4 }, FIXTURE.placement.back)).status).toBe(
			201,
		);
	});
});

describe("a designer link's pages", () => {
	function link(json: object) {
		return send(fake, '/v1/templates/mini-tee/designer-links', {
			method: 'POST',
			headers: { authorization: `Bearer ${FIXTURE.store.main.integrationKey}` },
			json: {
				blankId: FIXTURE.blank.tee,
				placementId: FIXTURE.placement.front,
				returnUrl: `${SITE_URL}/`,
				...json,
			},
		});
	}

	it('is the count its hosted designer makes the book at', async () => {
		const made = await link({ pages: { min: 4, max: 4 } });
		expect(made.status).toBe(201);
		const { url } = (await made.json()) as { url: string };

		const approved = await send(fake, `${new URL(url).pathname}/approve`);

		const proofSet = new URL(
			approved.headers.get('location') ?? '',
		).searchParams.get('proofSet');
		const set = await send(fake, `/v1/proof-sets/${proofSet}`, {
			headers: { authorization: `Bearer ${FIXTURE.store.main.integrationKey}` },
		});
		const { proofs } = (await set.json()) as { proofs: { page: number }[] };
		expect(proofs.map(({ page }) => page)).toEqual([1, 2, 3, 4]);
	});

	it('is refused off the placement’s own counts', async () => {
		for (const json of [
			{ pages: { min: 3, max: 3 } },
			{ pages: { min: 2, max: 8 } },
			{
				placementId: FIXTURE.placement.back,
				pages: { min: 2, max: 2 },
			},
		]) {
			const refused = await link(json);
			expect(refused.status).toBe(422);
			expect((await refused.json()).error).toBe('link_pages_off_range');
		}
	});
});
