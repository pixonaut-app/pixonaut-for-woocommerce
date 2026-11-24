import { describe, expect, it, vi } from 'vitest';
import type { PixonautDesigner } from '../types/sdk';
import { carryPageValues, keptPagesOf, withKeptPages } from './kept-pages';

const OFFERED = { min: 2, max: 8, step: 2 };

const page = (content?: string) => ({
	templateId: 'plain@1',
	values: content === undefined ? {} : { title: { content } },
});

const KEPT = {
	saved: {
		count: 4,
		page: 2,
		pages: [page('One'), page('Two'), page(), page('Four'), page('Five')],
	},
	templateId: 'plain@1',
	keys: new Map<string, string>(),
};

/** A book's designer on `templateId`, writing down what each page is given. */
function book({
	count = 4,
	shown = 2,
	templateId = 'other@1',
	layers = [] as object[],
	fields = [{ layerId: 'title', prop: 'content' }],
	refuses = undefined as unknown,
} = {}) {
	let pages = { count, page: shown, shown: true, placementId: 'p', ...OFFERED };
	const given: Array<[number, string, string, unknown]> = [];
	const designer = {
		getPages: () => pages,
		setPage: vi.fn((to: number) => {
			if (to > pages.count) {
				throw new TypeError(`setPage: ${to}`);
			}
			pages = { ...pages, page: to };
		}),
		setPageCount: vi.fn((to: number) => {
			pages = { ...pages, count: to };
		}),
		getState: () => ({ templateId, values: {} }),
		getTemplate: () => ({ layers }),
		getFormSchema: () => ({ steps: [{ fields }] }),
		setValue: (layerId: string, prop: string, value: unknown) => {
			if (value === refuses) {
				throw new TypeError('setValue');
			}
			given.push([pages.page, layerId, prop, value]);
		},
	};
	return {
		designer: designer as unknown as PixonautDesigner,
		given,
		pages: () => pages,
		carry: (kept = KEPT) =>
			carryPageValues(
				designer as unknown as PixonautDesigner,
				kept,
				designer.setPageCount,
			),
	};
}

describe('carryPageValues()', () => {
	it('gives each page its values on another template, the ones past the count too, and goes back to the page and count shown', () => {
		const { carry, given, pages } = book();

		carry();

		expect(given).toEqual([
			[1, 'title', 'content', 'One'],
			[4, 'title', 'content', 'Four'],
			[5, 'title', 'content', 'Five'],
		]);
		expect(pages()).toMatchObject({ page: 2, count: 4 });
	});

	it('gives the page shown its own when the one shown before was past the count', () => {
		const { carry, given } = book({ count: 2, shown: 2 });

		carry({ ...KEPT, saved: { ...KEPT.saved, page: 5 } });

		expect(given).toContainEqual([2, 'title', 'content', 'Two']);
	});

	it('carries a keyed field’s value to the field of its key', () => {
		const { carry, given } = book({
			layers: [{ id: 'name', props: { content: { fieldKey: 'who' } } }],
			fields: [{ layerId: 'name', prop: 'content' }],
		});

		carry({ ...KEPT, keys: new Map([['title:content', 'who']]) });

		expect(given[0]).toEqual([1, 'name', 'content', 'One']);
	});

	it('still gives the other pages theirs when one refuses a value', () => {
		const logged = vi
			.spyOn(console, 'error')
			.mockImplementation(() => undefined);
		const { carry, given, pages } = book({ refuses: 'One' });

		carry();

		expect(given.map(([, , , value]) => value)).toEqual(['Four', 'Five']);
		expect(pages()).toMatchObject({ page: 2, count: 4 });
		expect(logged).toHaveBeenCalledOnce();
		logged.mockRestore();
	});

	it('leaves the pages alone on the template they were designed on', () => {
		const { carry, given, pages } = book({ templateId: 'plain@1' });

		carry();

		expect(given).toEqual([]);
		expect(pages()).toMatchObject({ page: 2, count: 4 });
	});
});

describe('keptPagesOf() and withKeptPages()', () => {
	it('hands a designer’s pages on under another placement', () => {
		const { designer } = book();
		const values = { $sides: { $pages: { p: KEPT.saved }, $links: {} } };
		const kept = keptPagesOf({
			...designer,
			getState: () => ({ templateId: 'plain@1', values }),
		} as unknown as PixonautDesigner);

		expect(kept).toEqual(KEPT);
		expect(
			withKeptPages({ title: {} }, kept, { placementId: 'inside' }),
		).toEqual({
			title: {},
			$sides: { $pages: { inside: KEPT.saved } },
		});
	});

	it('keeps none of a side of one page', () => {
		const { designer } = book();

		expect(
			keptPagesOf({
				...designer,
				getPages: () => null,
			} as unknown as PixonautDesigner),
		).toBeUndefined();
		const values = { title: { content: 'One' } };
		expect(withKeptPages(values, undefined, { placementId: 'p' })).toBe(values);
	});
});
