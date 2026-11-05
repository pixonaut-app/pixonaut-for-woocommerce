// What the sides repeater needs that does not touch the page: the index a new
// side or size row gets, and its markup with that index in place.

/** Placeholder index in a new side's markup (`ProductPanel::NEW_SIDE_INDEX`). */
export const NEW_SIDE_INDEX = '__side__';

/** Placeholder index in a new size row's markup (`ProductPanel::NEW_SIZE_INDEX`). */
export const NEW_SIZE_INDEX = '__size__';

/** A size row's `kind` with its own width and height (`PostedSides::SIZE_CUSTOM`). */
export const CUSTOM_SIZE = 'custom';

/** A size row's `kind` taken from the placement (`PostedSides::SIZE_PLACEMENT_DEFAULT`). */
export const PLACEMENT_DEFAULT_SIZE = 'default';

/** The index after the highest one in use, so a new row never takes the
 *  posted name of one already on the page. */
export function nextIndex(inUse: string[]): number {
	let next = 0;
	for (const index of inUse) {
		const n = Number.parseInt(index, 10);
		if (Number.isInteger(n) && n >= next) {
			next = n + 1;
		}
	}
	return next;
}

/** `markup` with every `placeholder` replaced by `index`. */
export function withIndex(
	markup: string,
	placeholder: string,
	index: number,
): string {
	return markup.split(placeholder).join(String(index));
}
