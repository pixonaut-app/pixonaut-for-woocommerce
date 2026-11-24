import { beforeEach, describe, expect, it } from 'vitest';
import { createFake } from '../../fake-pixonaut/src/app';
import { FIXTURE } from '../../fake-pixonaut/src/fixture-ids';
import { PROOF_LIFETIME_MS } from '../../fake-pixonaut/src/proofs';
import { bearer, MAIN, SITE_URL, send } from './support';

const fake = createFake();
const asStore = bearer(MAIN.integrationKey);

const RETURN_URL = `${SITE_URL}/?pixonaut_designer=return`;
/** The pages the book side is approved at. */
const PAGES = 3;
const CONSENT = { kind: 'checkbox', at: Date.parse('2026-11-23T12:00:00Z') };

const COVER = {
	key: 'cover',
	label: 'Cover',
	blankId: FIXTURE.blank.tee,
	placementId: FIXTURE.placement.front,
};
const PAGES_SIDE = {
	key: 'pages',
	label: 'Pages',
	blankId: FIXTURE.blank.tee,
	placementId: FIXTURE.placement.back,
};

type SetProof = { key: string | null; page: number | null; proofToken: string };

beforeEach(() => {
	fake.reset('default', SITE_URL);
});

function post(path: string, json: unknown, key?: string) {
	return send(fake, path, {
		method: 'POST',
		json,
		headers:
			key === undefined ? asStore : { ...asStore, 'idempotency-key': key },
	});
}

/** Approves a cover and a book of `PAGES` pages on the hosted designer, and
 *  answers where it sends the shopper; `query` asks for other pages. */
async function approveBook(query = `pages[pages]=${PAGES}`): Promise<URL> {
	const link = await post('/v1/templates/mini-tee/designer-links', {
		sides: [COVER, PAGES_SIDE],
		returnUrl: RETURN_URL,
	});
	expect(link.status).toBe(201);
	const path = new URL(((await link.json()) as { url: string }).url).pathname;
	const back = await send(fake, `${path}/approve?${query}`);
	expect(back.status).toBe(302);
	return new URL(back.headers.get('location') ?? '');
}

async function readSet(ref: string): Promise<Response> {
	return send(fake, `/v1/proof-sets/${ref}`, { headers: asStore });
}

/** Commits and approves each proof of a set, answering the design ids. */
async function approvedDesigns(proofs: SetProof[]): Promise<string[]> {
	const ids: string[] = [];
	for (const proof of proofs) {
		const commit = await post(
			'/v1/designs',
			{ proofToken: proof.proofToken, externalRef: 'wc:site:cart:1' },
			`commit:${proof.proofToken}`,
		);
		expect(commit.status).toBe(201);
		const { designId } = (await commit.json()) as { designId: string };
		const approved = await post(`/v1/designs/${designId}/approve`, {
			consent: CONSENT,
			expectedRevision: 1,
		});
		expect(approved.status).toBe(200);
		ids.push(designId);
	}
	return ids;
}

describe('a hosted approval of a side printed as pages', () => {
	it('returns a proof set, a proof for the cover and one per page in order', async () => {
		const back = await approveBook();

		expect(back.searchParams.has('proofToken[cover]')).toBe(false);
		expect(back.searchParams.has('proofToken[pages]')).toBe(false);
		const response = await readSet(back.searchParams.get('proofSet') ?? '');
		expect(response.status).toBe(200);
		const { proofs } = (await response.json()) as { proofs: SetProof[] };
		expect(proofs.map(({ key, page }) => [key, page])).toEqual([
			['cover', null],
			['pages', 1],
			['pages', 2],
			['pages', 3],
		]);
		expect(fake.violations).toEqual([]);
	});

	it("commits each page as a design carrying its page and the book's count", async () => {
		const back = await approveBook();
		const { proofs } = (await (
			await readSet(back.searchParams.get('proofSet') ?? '')
		).json()) as { proofs: SetProof[] };

		const [, second] = await approvedDesigns(proofs.slice(1, 3));

		const design = await send(fake, `/v1/designs/${second}`, {
			headers: asStore,
		});
		expect(await design.json()).toMatchObject({ page: 2, pageCount: PAGES });
		expect(fake.violations).toEqual([]);
	});

	it("answers 404 for another store's or an unknown set, and 410 once its proofs expired", async () => {
		const back = await approveBook();
		const ref = back.searchParams.get('proofSet') ?? '';

		const other = await send(fake, `/v1/proof-sets/${ref}`, {
			headers: bearer(FIXTURE.store.other.integrationKey),
		});
		expect(other.status).toBe(404);
		expect(await other.json()).toEqual({ error: 'unknown_proof_set' });
		fake.clock.advance(PROOF_LIFETIME_MS);
		const expired = await readSet(ref);
		expect(expired.status).toBe(410);
		expect(await expired.json()).toEqual({ error: 'proof_set_expired' });
		expect(fake.violations).toEqual([]);
	});
});

describe('POST /v1/orders naming pages', () => {
	async function book(): Promise<{ cover: string; pages: string[] }> {
		const back = await approveBook();
		const { proofs } = (await (
			await readSet(back.searchParams.get('proofSet') ?? '')
		).json()) as { proofs: SetProof[] };
		const [cover = '', ...pages] = await approvedDesigns(proofs);
		return { cover, pages };
	}

	function order(designs: Record<string, unknown>[], key: string) {
		return post(
			'/v1/orders',
			{ designs, variant: FIXTURE.variant.teeWhiteS },
			key,
		);
	}

	it('orders the cover and every page of the book, each page in its placement of pages', async () => {
		const { cover, pages } = await book();

		const response = await order(
			[
				{ designId: cover },
				...pages.map((designId, index) => ({ designId, page: index + 1 })),
			],
			'o1',
		);

		expect(response.status).toBe(201);
		expect(fake.violations).toEqual([]);
	});

	it.each([
		['a page missing', (pages: string[]) => pages.slice(0, -1), 'page_missing'],
		[
			'a page named as another',
			(pages: string[]) => [...pages].reverse(),
			'page_not_as_proofed',
		],
	])('refuses %s', async (_, chosen, error) => {
		const { cover, pages } = await book();

		const response = await order(
			[
				{ designId: cover },
				...chosen(pages).map((designId, index) => ({
					designId,
					page: index + 1,
				})),
			],
			'o2',
		);

		expect(response.status).toBe(422);
		expect(((await response.json()) as { error: string }).error).toBe(error);
	});

	it('checks each placement of pages as a book of its own', async () => {
		const COVER_PAGES = 2;
		const back = await approveBook(
			`pages[cover]=${COVER_PAGES}&pages[pages]=${PAGES}`,
		);
		const { proofs } = (await (
			await readSet(back.searchParams.get('proofSet') ?? '')
		).json()) as { proofs: SetProof[] };
		const ids = await approvedDesigns(proofs);
		const named = proofs.map((proof, index) => ({
			designId: ids[index],
			page: proof.page,
		}));

		const missing = await order(named.slice(0, -1), 'o4');
		expect(missing.status).toBe(422);
		expect(await missing.json()).toEqual({ error: 'page_missing' });
		const whole = await order(named, 'o5');
		expect(whole.status).toBe(201);
		expect(fake.violations).toEqual([]);
	});

	it('refuses a page on a design of no pages', async () => {
		const { cover, pages } = await book();

		const response = await order(
			[
				{ designId: cover, page: 1 },
				...pages.map((designId, index) => ({ designId, page: index + 1 })),
			],
			'o3',
		);

		expect(response.status).toBe(422);
		expect(await response.json()).toEqual({
			error: 'page_on_unpaged_placement',
		});
	});
});
