// @vitest-environment jsdom
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { ProductDesigner } from './designer';
import { nextPageCount, printedPages, withPages } from './pages';
import type { SideReads } from './server-reads';
import {
	fakeDesigner,
	fakeSdk,
	MOUNTS,
	modalDialogs,
	PagesChangedError,
	PROOF,
	pageDeps,
	productPage,
	settle,
	TWO_SIDES,
} from './test-support';
import { VARIATION_SETTLE_MS } from './variations';

const COUNTS = { min: 24, max: 100 };

const PAGES = {
	templateId: 'mini-tee@2',
	blankId: 'blank_tee',
	placementId: 'front',
	placements: [{ placementId: 'front' }],
	printSizes: [0],
};

const COVER = {
	...PAGES,
	placementId: 'back',
	placements: [{ placementId: 'back' }],
};

const MOUNTED = {
	front: { ...MOUNTS.front, placements: PAGES.placements },
};

function sold(size: string, pageCount?: number, sides: object = {}) {
	return {
		state: 'personalised',
		attributes: { attribute_size: size },
		approvalMode: 'add_to_cart',
		sides: { front: PAGES, ...sides },
		...(pageCount === undefined ? {} : { pageCount }),
	};
}

/** A book sold at 44 pages and at 60. */
const BOOK = { '11': sold('S', 44), '12': sold('L', 60) };

function start({
	sdk = fakeSdk(
		fakeDesigner({ pages: { count: 24, placementId: 'front', ...COUNTS } }),
	),
	mounts = MOUNTED as object,
	variations = BOOK as object | null,
	pageCount = undefined as string | undefined,
	checkbox = false,
	fieldDelay = 0,
} = {}) {
	const page = productPage(mounts, {
		...(variations === null ? {} : { variations }),
		checkbox,
	});
	if (pageCount !== undefined) {
		page.container.dataset.pixonautPageCount = pageCount;
	}
	const size = page.form.querySelector<HTMLSelectElement>(
		'[name="attribute_size"]',
	);
	const field = page.form.querySelector<HTMLInputElement>(
		'[name="variation_id"]',
	);
	// As WooCommerce does once the attributes name a variation; the block
	// form after `fieldDelay`.
	size?.addEventListener('change', () => {
		const found = Object.entries(variations ?? {}).find(
			([, { attributes }]) => attributes.attribute_size === size.value,
		);
		const hold = () => {
			if (field !== null) {
				field.value = found?.[0] ?? '0';
			}
		};
		if (fieldDelay > 0) {
			setTimeout(hold, fieldDelay);
		} else {
			hold();
		}
	});
	const designer = new ProductDesigner(page.container, {
		loadSdk: () => Promise.resolve(sdk.sdk),
		label: (key) => `label:${key}`,
		report: vi.fn(),
		...pageDeps(),
	});
	designer.start();
	return { ...page, ...sdk, size, field, book: sdk.designer };
}

async function settled(): Promise<void> {
	await settle();
	await new Promise((resolve) => setTimeout(resolve, VARIATION_SETTLE_MS));
	await settle();
}

async function choose(
	{ form, field }: { form: HTMLFormElement; field: HTMLInputElement | null },
	id: string,
): Promise<void> {
	if (field === null) {
		throw new Error('The form has no variation field.');
	}
	field.value = id;
	form.dispatchEvent(new Event('change', { bubbles: true }));
	await settled();
}

describe('the pages of a book', () => {
	beforeEach(() => {
		vi.spyOn(console, 'error').mockImplementation(() => undefined);
	});

	afterEach(() => {
		vi.restoreAllMocks();
	});

	it('follows the variation to the pages it is sold at, without mounting again', async () => {
		const page = start();
		await settled();

		await choose(page, '11');
		expect(page.book.getPages()?.count).toBe(44);
		await choose(page, '12');
		expect(page.book.getPages()?.count).toBe(60);

		expect(page.mount).toHaveBeenCalledOnce();
		expect(page.field?.value).toBe('12');
	});

	it('leaves the pages alone on a variation sold at none', async () => {
		const page = start({ variations: { '11': sold('S') } });
		await settled();

		await choose(page, '11');

		expect(page.book.setPageCount).not.toHaveBeenCalled();
		expect(page.book.setItemPageCount).not.toHaveBeenCalled();
	});

	it('shows a product without variations at its own pages, and takes the shopper back to them', async () => {
		const page = start({ variations: null, pageCount: '60' });
		page.button.click();
		await settled();
		expect(page.book.getPages()?.count).toBe(60);

		page.book.setPageCount(62);
		await settled();

		expect(page.book.getPages()?.count).toBe(60);
	});

	it('selects the variation sold at the pages the shopper picks', async () => {
		const page = start();
		await settled();
		await choose(page, '11');

		page.book.setPageCount(60);
		await settled();

		expect(page.size?.value).toBe('L');
		expect(page.field?.value).toBe('12');
		expect(page.book.getPages()?.count).toBe(60);
	});

	it('steps on to the next pages sold, and back where none are', async () => {
		const page = start();
		await settled();
		await choose(page, '11');

		page.book.setPageCount(46);
		await settled();
		expect(page.book.getPages()?.count).toBe(60);
		expect(page.field?.value).toBe('12');

		page.book.setPageCount(62);
		await settled();
		expect(page.book.getPages()?.count).toBe(60);
		expect(page.field?.value).toBe('12');
	});

	it.each([
		['as it is chosen', 0],
		['a moment after it is chosen', VARIATION_SETTLE_MS / 2],
	])(
		'proofs the pages picked when the proof is opened before the form settles, its field holding the variation %s',
		async (_, fieldDelay) => {
			modalDialogs();
			const book = Object.fromEntries(
				Object.entries(BOOK).map(([id, variation]) => [
					id,
					{ ...variation, approvalMode: 'checkbox' },
				]),
			);
			const page = start({ variations: book, checkbox: true, fieldDelay });
			await settled();
			await choose(page, '11');
			const proofed: (number | undefined)[] = [];
			page.book.approve.mockImplementation(() => {
				proofed.push(page.book.getPages()?.count);
				return Promise.resolve(PROOF);
			});

			page.book.setPageCount(60);
			await settle();
			page.container
				.querySelector<HTMLButtonElement>('.pixonaut-approval__proof')
				?.click();
			await settled();

			expect(page.field?.value).toBe('12');
			expect(proofed).toEqual([60]);
		},
	);

	it('changes nothing when a page is turned', async () => {
		const page = start();
		await settled();
		await choose(page, '11');
		page.book.setPageCount.mockClear();
		const shown = page.book.on.mock.calls.find(
			([event]) => event === 'pages',
		)?.[1];

		shown({ count: 44, page: 3, ...COUNTS });
		await settled();

		expect(page.book.setPageCount).not.toHaveBeenCalled();
		expect(page.field?.value).toBe('11');
	});

	it('tells the cover’s designer the pages of the item, once each', async () => {
		const book = fakeDesigner({ pages: { count: 24, ...COUNTS } });
		const cover = fakeDesigner();
		const sdk = fakeSdk(book);
		sdk.mount.mockImplementation(({ placementId }) =>
			Promise.resolve(placementId === 'back' ? cover : book),
		);
		const page = start({
			sdk,
			mounts: {
				// The cover is mounted before the pages are known.
				back: { ...TWO_SIDES.back, placements: COVER.placements },
				...MOUNTED,
			},
			variations: {
				'11': sold('S', 44, { back: COVER }),
				'12': sold('L', 60, { back: COVER }),
			},
		});
		await settled();

		await choose(page, '11');
		await choose(page, '12');

		expect(cover.setItemPageCount.mock.calls).toEqual([[44], [60]]);
		expect(cover.setPageCount).not.toHaveBeenCalled();
		expect(book.setItemPageCount).not.toHaveBeenCalled();
	});

	it('tells a blank without pages nothing', async () => {
		const page = start({ sdk: fakeSdk() });
		await settled();

		await choose(page, '11');

		expect(page.book.setItemPageCount).not.toHaveBeenCalled();
	});

	it('approves again when the pages changed while the proof was made', async () => {
		const page = start();
		await settled();
		await choose(page, '11');
		page.book.approve
			.mockRejectedValueOnce(new PagesChangedError())
			.mockResolvedValueOnce({ ...PROOF, proofToken: 'prf_tok_after' });
		page.form.addEventListener('submit', (event) => event.preventDefault());

		page.button.click();
		await settled();

		expect(page.book.approve).toHaveBeenCalledTimes(2);
		expect(
			page.form.querySelector<HTMLInputElement>(
				'[name="pixonaut_designs[front]"]',
			)?.value,
		).toBe('prf_tok_after');
	});

	it('adds every page’s proof, in page order', async () => {
		const page = start();
		await settled();
		await choose(page, '11');
		const proof = (number: number) => ({
			...PROOF,
			placementId: 'front',
			page: number,
			proofToken: `prf_tok_${number}`,
		});
		const sides = [proof(1), proof(3), proof(2)];
		page.book.approve.mockResolvedValue({ ...proof(1), sides });
		const sent: FormData[] = [];
		page.form.addEventListener('submit', (event) => {
			event.preventDefault();
			sent.push(new FormData(page.form));
		});

		page.button.click();
		await settled();

		const tokens = [...(sent.at(-1)?.entries() ?? [])].filter(([name]) =>
			name.startsWith('pixonaut_designs['),
		);
		expect(tokens).toEqual([
			['pixonaut_designs[front][1]', 'prf_tok_1'],
			['pixonaut_designs[front][2]', 'prf_tok_2'],
			['pixonaut_designs[front][3]', 'prf_tok_3'],
		]);
		expect(
			page.form.querySelector<HTMLInputElement>(
				'[name="pixonaut_fields[front]"]',
			)?.value,
		).toBe('[]');
	});

	it('mounts again on every page’s values, under the blank’s own placement', async () => {
		const other = { ...PAGES, blankId: 'blank_hoodie', placementId: 'inside' };
		const page = start({
			variations: {
				'11': sold('S', 44),
				'12': sold('L', 60, {
					front: { ...other, placements: [{ placementId: 'inside' }] },
				}),
			},
		});
		await settled();
		await choose(page, '11');
		const book = {
			count: 44,
			page: 2,
			pages: [
				{ templateId: 'mini-tee@2', values: { slogan: { content: 'One' } } },
				{ templateId: 'mini-tee@2', values: { slogan: { content: 'Two' } } },
			],
		};
		const values = {
			slogan: { content: 'Two' },
			$sides: { $pages: { front: book } },
		};
		page.book.getState.mockReturnValue({ templateId: 'mini-tee@2', values });

		await choose(page, '12');

		expect(page.mount).toHaveBeenCalledTimes(2);
		expect(page.mount.mock.calls[1]?.[0].initialValues).toEqual({
			slogan: { content: 'Two' },
			$sides: { $pages: { inside: book } },
		});
	});

	it('adds nothing at a count Pixonaut does not offer', async () => {
		const page = start({ variations: { '11': sold('S', 144) } });
		await settled();
		await choose(page, '11');

		page.button.click();
		await settled();

		expect(page.book.approve).not.toHaveBeenCalled();
		expect(
			page.container.querySelector<HTMLElement>('.pixonaut-form__error')
				?.hidden,
		).toBe(false);
	});
});

describe('withPages()', () => {
	const paged = { pages: { min: 24, max: 100, step: 4 } };
	const options = (placements?: object[]) =>
		({ placementId: 'pages', ...(placements ? { placements } : {}) }) as never;

	it('offers the counts sold on the placement printed as pages', () => {
		expect(
			withPages(
				options([{ placementId: 'cover' }, { placementId: 'pages' }]),
				paged,
				[44, 60],
				60,
			),
		).toEqual({
			placementId: 'pages',
			placements: [
				{ placementId: 'cover' },
				{ placementId: 'pages', pages: { min: 44, max: 60 }, pageCount: 60 },
			],
		});
	});

	it('offers them on a mount of one placement', () => {
		expect(withPages(options(), paged, [44], undefined)).toEqual({
			placementId: 'pages',
			pages: { min: 44, max: 44 },
		});
	});

	it('leaves out a count the placement is not printed at', () => {
		expect(withPages(options(), paged, [45, 144], 45)).toEqual(options());
		expect(withPages(options(), paged, [45, 60], 45)).toEqual({
			placementId: 'pages',
			pages: { min: 60, max: 60 },
		});
	});

	it('names none without the reads, and leaves a filter’s own', () => {
		expect(withPages(options(), null, [44], 44)).toEqual(options());
		const own = options([{ placementId: 'pages', pageCount: 24 }]);
		expect(withPages(own, paged, [44], 44)).toEqual(own);
	});
});

describe('printedPages()', () => {
	it('names the placements printed as pages', () => {
		const reads = {
			blank: {
				mockups: {
					placements: [
						{ id: 'cover' },
						{ id: 'pages', pages: { min: 24, max: 100, step: 2 } },
					],
				},
			},
		} as unknown as SideReads;

		expect(printedPages(reads)).toEqual({
			pages: { min: 24, max: 100, step: 2 },
		});
		expect(printedPages({})).toBeNull();
		expect(printedPages(undefined)).toBeNull();
	});
});

describe('nextPageCount()', () => {
	it('is the next one sold the way the shopper stepped', () => {
		expect(nextPageCount([24, 44, 60], 44, 46)).toBe(60);
		expect(nextPageCount([24, 44, 60], 44, 42)).toBe(24);
		expect(nextPageCount([24, 44, 60], 60, 62)).toBeUndefined();
	});
});
