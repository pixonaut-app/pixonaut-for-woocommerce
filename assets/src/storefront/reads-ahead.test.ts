// @vitest-environment jsdom
import { afterEach, describe, expect, it, vi } from 'vitest';
import { ReadsAhead } from './reads-ahead';
import type { SideReads } from './server-reads';

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
	body: unknown = { reads: [{ ...ON_HOODIE, reads: HOODIE }] },
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

		ahead.ask();
		ahead.ask();
		expect(fetcher).not.toHaveBeenCalled();
		await idle();

		expect(fetcher).toHaveBeenCalledOnce();
		expect(String(fetcher.mock.calls[0]?.[0])).toBe(
			'https://shop.test/wp-json/pixonaut/v1/storefront/reads-ahead?product=42',
		);
	});

	it('adds the product to a route asked by its query', async () => {
		vi.useFakeTimers();
		const fetcher = answering();
		new ReadsAhead(
			'https://shop.test/?rest_route=/pixonaut/v1/storefront/reads-ahead',
			42,
			fetcher,
		).ask();
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

		ahead.ask();
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

	it('leaves each switch to read for itself when the ask fails', async () => {
		vi.useFakeTimers();
		const fetcher = vi.fn<typeof fetch>(
			async () => new Response('', { status: 500 }),
		);
		const ahead = new ReadsAhead('https://shop.test/reads', 42, fetcher);
		ahead.ask();
		await idle();

		expect(ahead.readsFor(ON_HOODIE, undefined, undefined)).toBeUndefined();
	});

	it('asks nothing without a route', () => {
		const fetcher = answering();
		new ReadsAhead(undefined, 42, fetcher).ask();

		expect(fetcher).not.toHaveBeenCalled();
	});
});
