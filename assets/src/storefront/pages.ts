import type {
	PixonautDesigner,
	ProductPlacement,
	SplitMountOptions,
} from '../types/sdk';
import type { SideReads } from './server-reads';
import type { Variations } from './variations';

/** The page counts a placement printed as pages is printed at, as its
 *  blank's mockups name them. */
export type PrintedPages = { min: number; max: number; step?: number };

/**
 * The page count the item is sold at: on a variable product the chosen
 * variation's (none before one is chosen, or when it is sold at none); else
 * the product's own, from the form container's `data-pixonaut-page-count`
 * (none on a product sold at none).
 */
export function pageCount(
	container: HTMLElement,
	variations: Variations | null,
	id: string,
): number | undefined {
	if (variations === null) {
		const own = Number(container.dataset.pixonautPageCount);
		return Number.isInteger(own) && own > 0 ? own : undefined;
	}
	const variation = variations[id];
	return variation?.state === 'personalised' ? variation.pageCount : undefined;
}

/** Every page count the product is sold at, fewest first. */
export function pageCountsSold(
	container: HTMLElement,
	variations: Variations | null,
): number[] {
	const counts = Object.keys(variations ?? { '': null }).flatMap(
		(id) => pageCount(container, variations, id) ?? [],
	);
	return [...new Set(counts)].sort((first, second) => first - second);
}

/** The placements of the blank that are printed as pages, by id, as the
 *  server's reads name them; null without the reads. */
export function printedPages(
	reads: SideReads | undefined,
): Record<string, PrintedPages> | null {
	const placements = reads?.blank?.mockups.placements;
	if (placements === undefined) {
		return null;
	}
	const paged: Record<string, PrintedPages> = {};
	for (const { id, pages } of placements) {
		if (pages !== undefined) {
			paged[id] = pages;
		}
	}
	return paged;
}

/** Whether `count` is one of the counts `pages` is printed at. */
function printedAt(pages: PrintedPages, count: number): boolean {
	return (
		count >= pages.min &&
		count <= pages.max &&
		(count - pages.min) % (pages.step ?? 1) === 0
	);
}

/**
 * `options` with the counts sold offered on the placement printed as pages,
 * and the item starting at `count`. Every count is left out that the
 * placement is not printed at: the SDK refuses to mount with one. Options
 * that name either already, a filter's, are left alone.
 */
export function withPages(
	options: SplitMountOptions,
	paged: Record<string, PrintedPages> | null,
	sold: readonly number[],
	count: number | undefined,
): SplitMountOptions {
	const offered = (placementId: string | undefined) => {
		const pages = placementId === undefined ? undefined : paged?.[placementId];
		const counts =
			pages === undefined ? [] : sold.filter((each) => printedAt(pages, each));
		const [min] = counts;
		const max = counts.at(-1);
		return min === undefined || max === undefined
			? {}
			: {
					pages: { min, max },
					...(count !== undefined && counts.includes(count)
						? { pageCount: count }
						: {}),
				};
	};
	const named = (each: { pages?: unknown; pageCount?: unknown }) =>
		each.pages !== undefined || each.pageCount !== undefined;
	const { placements } = options as {
		placements?: readonly ProductPlacement[];
	};
	// A mount that names the placements sold offers each one's own counts.
	if (placements !== undefined && placements.length > 0) {
		return {
			...options,
			placements: placements.map((each) =>
				named(each) ? each : { ...each, ...offered(each.placementId) },
			),
		} as SplitMountOptions;
	}
	return named(options as { pages?: unknown; pageCount?: unknown })
		? options
		: ({
				...options,
				...offered((options as { placementId?: string }).placementId),
			} as SplitMountOptions);
}

/**
 * The count sold the customer stepped towards, from the count they left
 * (`from`) to one nothing is sold at; undefined with none that way.
 */
export function nextPageCount(
	sold: readonly number[],
	from: number,
	to: number,
): number | undefined {
	return to > from
		? sold.find((count) => count > to)
		: sold.filter((count) => count < to).at(-1);
}

/**
 * Puts each designer at `count`, the pages the item has: the one of the side
 * printed as pages shows that many, and any other, such as a book's cover,
 * is drawn and proofed for a book of that many. A designer of a blank none
 * of whose sides is printed as pages is told nothing: Pixonaut refuses a
 * count there. Each designer told is named to `told` before it is. Rejects a
 * count Pixonaut does not offer with the SDK's `TypeError`.
 */
export function showPageCount(
	designers: Iterable<{ designer: PixonautDesigner; ofBook: boolean }>,
	count: number,
	shownAt: (designer: PixonautDesigner) => number | undefined,
	told: (designer: PixonautDesigner) => void,
): void {
	const all = [...designers].map((each) => ({
		...each,
		pages: each.designer.getPages(),
	}));
	const book = all.some(({ pages }) => pages !== null);
	for (const { designer, ofBook, pages } of all) {
		if (pages !== null) {
			if (pages.count !== count) {
				told(designer);
				designer.setPageCount(count);
			}
		} else if ((book || ofBook) && shownAt(designer) !== count) {
			told(designer);
			designer.setItemPageCount(count);
		}
	}
}
