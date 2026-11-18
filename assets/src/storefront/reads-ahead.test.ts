// @vitest-environment jsdom
import { afterEach, describe, expect, it, vi } from 'vitest';
import { ReadsAhead } from './reads-ahead';
import { MS_PER_S, READS_MAX_AGE_S, type SideReads } from './server-reads';

const PRINTED: SideReads = { template: { id: 'printed' } as never };
const HOODIE: SideReads = { template: { id: 'hoodie' } as never };

const TEE = {
	templateId: 'mini-tee@2',
	blankId: 'blank_tee',
	placementId: 'front',
};
const ON_HOODIE = { ...TEE, blankId: 'blank_hoodie' };

/** A fetch answering the route with the hoodie's reads. */
function answering(
	body: unknown = {
		targets: [{ ...ON_HOODIE, reads: { template: 0 } }],
		parts: [HOODIE.template],
	},
) {
	return vi.fn<typeof fetch>(
		async () => new Response(JSON.stringify(body), { status: 200 }),
	);
}

/** Lets the page fall idle and the answer land. */
async function idle(): Promise<void> {
	vi.runAllTimers();
	vi.useRealTimers();
	await new Promise((resolve) => setTimeout(resolve, 0));
}

describe('ReadsAhead', () => {
	afterEach(() => {
		vi.useRealTimers();
	});

	it('asks once, when the page is idle, for the product', async () => {
		vi.useFakeTimers();
		const fetcher = answering();
		const ahead = new ReadsAhead(
			'https://shop.test/wp-json/pixonaut/v1/storefront/reads-ahead',
			42,
			fetcher,
		);

		ahead.ask([TEE]);
		ahead.ask([TEE]);
		expect(fetcher).not.toHaveBeenCalled();
		await idle();

		expect(fetcher).toHaveBeenCalledOnce();
		expect(String(fetcher.mock.calls[0]?.[0])).toBe(
			'https://shop.test/wp-json/pixonaut/v1/storefront/reads-ahead?product=42&printed%5B%5D=mini-tee%402%7Cblank_tee%7Cfront',
		);
	});

	it('adds the product to a route asked by its query', async () => {
		vi.useFakeTimers();
		const fetcher = answering();
		new ReadsAhead(
			'https://shop.test/?rest_route=/pixonaut/v1/storefront/reads-ahead',
			42,
			fetcher,
		).ask([]);
		await idle();

		expect(String(fetcher.mock.calls[0]?.[0])).toBe(
			'https://shop.test/?rest_route=%2Fpixonaut%2Fv1%2Fstorefront%2Freads-ahead&product=42',
		);
	});

	it('mounts a side on another blank from the reads asked ahead for it', async () => {
		vi.useFakeTimers();
		const ahead = new ReadsAhead('https://shop.test/reads', 42, answering());
		expect(ahead.readsFor(ON_HOODIE, PRINTED, TEE)).toEqual({
			reads: PRINTED,
			readFor: TEE,
		});

		ahead.ask([]);
		await idle();

		expect(ahead.readsFor(ON_HOODIE, PRINTED, TEE)).toEqual({
			reads: HOODIE,
			readFor: ON_HOODIE,
		});
		expect(
			ahead.readsFor(TEE, PRINTED, TEE),
			'the printed reads first',
		).toEqual({
			reads: PRINTED,
			readFor: TEE,
		});
	});

	it('gives each target the answers it names, shared with the other targets', async () => {
		vi.useFakeTimers();
		const mockups = { views: [] };
		const variants = { axes: [] };
		const ON_POSTER = { ...ON_HOODIE, templateId: 'mini-poster@1' };
		const ahead = new ReadsAhead(
			'https://shop.test/reads',
			42,
			answering({
				targets: [
					{
						...ON_HOODIE,
						reads: { template: 0, blank: { mockups: 1, variants: 2 } },
					},
					{
						...ON_POSTER,
						reads: { template: 3, blank: { mockups: 1, variants: 2 } },
					},
					{ ...TEE, blankId: 'blank_lost', reads: { template: 9 } },
				],
				parts: [HOODIE.template, mockups, variants, { id: 'poster' }],
			}),
		);
		ahead.ask([]);
		await idle();

		const hoodie = ahead.readsFor(ON_HOODIE, undefined, undefined)?.reads;
		const poster = ahead.readsFor(ON_POSTER, undefined, undefined)?.reads;
		expect(hoodie).toEqual({ ...HOODIE, blank: { mockups, variants } });
		expect(poster).toEqual({
			template: { id: 'poster' },
			blank: { mockups, variants },
		});
		expect(poster?.blank?.mockups).toBe(hoodie?.blank?.mockups);
		expect(
			ahead.readsFor({ ...TEE, blankId: 'blank_lost' }, undefined, undefined),
			'a target naming a part the answer lacks',
		).toBeUndefined();
	});

	it('takes no reads from an answer of another shape', async () => {
		vi.useFakeTimers();
		const ahead = new ReadsAhead(
			'https://shop.test/reads',
			42,
			answering({ reads: [{ ...ON_HOODIE, reads: HOODIE }] }),
		);
		ahead.ask([]);
		await idle();

		expect(ahead.readsFor(ON_HOODIE, undefined, undefined)).toBeUndefined();
	});

	it('lets reads go once their photo links may have closed, and asks again', async () => {
		vi.useFakeTimers();
		let now = 0;
		const fetcher = answering();
		const ahead = new ReadsAhead(
			'https://shop.test/reads',
			42,
			fetcher,
			() => now,
		);
		ahead.ask([TEE]);
		await idle();
		expect(ahead.readsFor(ON_HOODIE, undefined, undefined)?.reads).toEqual(
			HOODIE,
		);

		now = (READS_MAX_AGE_S + 1) * MS_PER_S;

		expect(ahead.readsFor(ON_HOODIE, undefined, undefined)).toBeUndefined();
		vi.useFakeTimers();
		ahead.ask([TEE]);
		await idle();
		expect(fetcher).toHaveBeenCalledTimes(2);
	});

	it('leaves each switch to read for itself when the ask fails', async () => {
		vi.useFakeTimers();
		const fetcher = vi.fn<typeof fetch>(
			async () => new Response('', { status: 500 }),
		);
		const ahead = new ReadsAhead('https://shop.test/reads', 42, fetcher);
		ahead.ask([]);
		await idle();

		expect(ahead.readsFor(ON_HOODIE, undefined, undefined)).toBeUndefined();
	});

	it('asks nothing without a route', () => {
		const fetcher = answering();
		new ReadsAhead(undefined, 42, fetcher).ask([]);

		expect(fetcher).not.toHaveBeenCalled();
	});
});
