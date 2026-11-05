// The largest print a side's placement takes (Pixonaut's `maxWidthMm` and
// `maxHeightMm`): a custom size row says it under its width and height, and
// calls a value past it out.

export type SizeLimit = { widthMm: number; heightMm: number };

/** The side's chosen placement's largest print, from its option's
 *  `data-max-*`; null with no placement chosen or no limit known. */
export function placementLimit(side: HTMLElement): SizeLimit | null {
	const option = side.querySelector<HTMLSelectElement>(
		'.pixonaut-side-placement',
	)?.selectedOptions[0];
	const widthMm = Number(option?.dataset.maxWidthMm);
	const heightMm = Number(option?.dataset.maxHeightMm);
	return widthMm > 0 && heightMm > 0 ? { widthMm, heightMm } : null;
}

/** The most fractional digits `Intl.NumberFormat` writes, so a limit is shown
 *  exactly as `overLimit()` compares it, never rounded. */
const ALL_FRACTION_DIGITS = 20;

/** `message` with `%1$s` and `%2$s` as the limit's width and height, written
 *  as the page's language writes numbers. */
export function describeLimit(message: string, limit: SizeLimit): string {
	const format = new Intl.NumberFormat(
		document.documentElement.lang || undefined,
		{ maximumFractionDigits: ALL_FRACTION_DIGITS },
	);
	return message
		.replace('%1$s', format.format(limit.widthMm))
		.replace('%2$s', format.format(limit.heightMm));
}

/** Millimetres as typed, `.` or `,` as the decimal mark (as `PostedSides`
 *  reads them); NaN when it is not a number. */
function millimetres(typed: string): number {
	const trimmed = typed.trim().replace(',', '.');
	return trimmed === '' ? Number.NaN : Number(trimmed);
}

/** The width and height fields of a size row past `limit`. */
export function overLimit(
	row: HTMLElement,
	limit: SizeLimit | null,
): HTMLInputElement[] {
	if (limit === null) {
		return [];
	}
	const over: HTMLInputElement[] = [];
	const width = row.querySelector<HTMLInputElement>('.pixonaut-size-width');
	const height = row.querySelector<HTMLInputElement>('.pixonaut-size-height');
	if (width && millimetres(width.value) > limit.widthMm) {
		over.push(width);
	}
	if (height && millimetres(height.value) > limit.heightMm) {
		over.push(height);
	}
	return over;
}

/** Shows the limit under a custom size row, from the side's
 *  `data-size-limit`; hidden for a placement default or without a limit. */
export function showSizeLimit(
	side: HTMLElement,
	row: HTMLElement,
	custom: boolean,
): void {
	const hint = row.querySelector<HTMLElement>('.pixonaut-size-limit');
	if (!hint) {
		return;
	}
	const limit = placementLimit(side);
	const message = side.dataset.sizeLimit;
	hint.hidden = !custom || limit === null || message === undefined;
	hint.textContent =
		hint.hidden || limit === null || message === undefined
			? ''
			: describeLimit(message, limit);
}
