import type { PixonautDesigner } from '../types/sdk';
import { fieldKeys } from './template-carry';
import { baseIdOf } from './value-sets';

type Values = ReturnType<PixonautDesigner['getState']>['values'];

/** Where a designer's values keep its item's other sides, and beside them
 *  the pages of a side printed as pages, by placement. */
const SIDES_KEY = '$sides';
const PAGES_KEY = '$pages';

/** One page as a designer's values keep it. */
type SavedPage = {
	templateId: string;
	values: Record<string, Record<string, unknown> | undefined>;
};

/** A side's pages as a designer's values keep them: the pages from 1, the
 *  ones past the count among them. */
type SavedPages = { count: number; page: number; pages: SavedPage[] };

/** The pages a designer held, and the template they were designed on. */
export type KeptPages = {
	saved: SavedPages;
	templateId: string;
	/** The template's field keys, see `fieldKeys()`. */
	keys: ReadonlyMap<string, string>;
};

function isRecord(value: unknown): value is Record<string, unknown> {
	return typeof value === 'object' && value !== null && !Array.isArray(value);
}

/** The pages a designer holds; undefined for one of a side of one page. */
export function keptPagesOf(designer: PixonautDesigner): KeptPages | undefined {
	const placementId = designer.getPages()?.placementId;
	const { templateId, values } = designer.getState();
	const pages: unknown = values[SIDES_KEY]?.[PAGES_KEY];
	const saved =
		isRecord(pages) && placementId !== undefined
			? pages[placementId]
			: undefined;
	return isRecord(saved) && Array.isArray(saved.pages)
		? {
				saved: saved as SavedPages,
				templateId,
				keys: fieldKeys(designer),
			}
		: undefined;
}

/**
 * `values` with the pages kept, under the placement the side mounts on now:
 * the SDK restores a side's pages by its placement, and another blank names
 * its own.
 */
export function withKeptPages(
	values: Values,
	kept: KeptPages | undefined,
	mount: object | undefined,
): Values {
	const { placementId } = (mount ?? {}) as { placementId?: string };
	return kept === undefined || placementId === undefined
		? values
		: {
				...values,
				[SIDES_KEY]: {
					...values[SIDES_KEY],
					[PAGES_KEY]: { [placementId]: kept.saved },
				},
			};
}

/**
 * Gives each page the values it was designed with on another template, on
 * the template the designer shows now: a field of a key takes the key's
 * value, any other the value of its layer. The SDK carries the page shown
 * alone, and keeps every other for the way back. Pages past the count are
 * reached by `setPageCount`, which is left at the count it found. Each
 * page is shown before it is given its values: a page laid in a template of
 * its own is shown once that template is drawn. Only a page saved on the
 * template `keys` name is carried: one on a page layout keeps its layout,
 * which the SDK keeps it on with its own values. Once `stale` answers true,
 * a newer carry has begun: no further page is carried, and the page and
 * count it found are shown again.
 */
export async function carryPageValues(
	designer: PixonautDesigner,
	{ saved, templateId, keys }: KeptPages,
	setPageCount: (count: number) => void,
	stale: () => boolean = () => false,
): Promise<void> {
	const opened = designer.getPages();
	if (
		opened === null ||
		!opened.shown ||
		designer.getState().templateId === templateId
	) {
		return;
	}
	const designed = saved.pages
		.map((page, index) => ({ ...page, number: index + 1 }))
		.filter(
			({ number, values, templateId: on }) =>
				// A page on a layout is not on the template the keys are of.
				(typeof on !== 'string' || baseIdOf(on) === baseIdOf(templateId)) &&
				// The page shown holds its own already, unless it was past the count.
				(number !== opened.page || opened.page !== saved.page) &&
				number <= opened.max &&
				Object.keys(values).length > 0,
		);
	const last = Math.max(0, ...designed.map(({ number }) => number));
	// The fewest pages offered that reach the last page designed.
	const reach =
		opened.min +
		Math.ceil(Math.max(0, last - opened.min) / opened.step) * opened.step;
	const raised = last > opened.count && reach <= opened.max;
	if (raised) {
		setPageCount(reach);
	}
	try {
		for (const { number, values } of designed) {
			if (stale()) {
				break;
			}
			if (raised || number <= opened.count) {
				await designer.setPage(number);
				givePage(designer, values, keys);
			}
		}
	} finally {
		await designer.setPage(opened.page);
		if (raised) {
			setPageCount(opened.count);
		}
	}
}

/** Gives the page shown the values of `given`, made where `keys` name the
 *  fields. */
function givePage(
	designer: PixonautDesigner,
	given: SavedPage['values'],
	keys: ReadonlyMap<string, string>,
): void {
	const byKey = new Map<string, unknown>();
	for (const [layerId, props] of Object.entries(given)) {
		for (const [prop, value] of Object.entries(props ?? {})) {
			const key = keys.get(`${layerId}:${prop}`);
			if (key !== undefined) {
				byKey.set(key, value);
			}
		}
	}
	const own = fieldKeys(designer);
	for (const step of designer.getFormSchema().steps) {
		for (const { layerId, prop } of step.fields) {
			const key = own.get(`${layerId}:${prop}`);
			const layer = given[layerId];
			const has =
				key === undefined
					? layer !== undefined && Object.hasOwn(layer, prop)
					: byKey.has(key);
			if (has) {
				try {
					designer.setValue(
						layerId,
						prop,
						key === undefined ? layer?.[prop] : byKey.get(key),
					);
				} catch (error) {
					// The field keeps its own; the others still take theirs.
					console.error('Pixonaut: a value could not be carried over.', error);
				}
			}
		}
	}
}
