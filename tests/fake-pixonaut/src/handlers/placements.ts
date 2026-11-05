// A blank's placement, as a template's constraints and a proof name it: the
// pair resolved on the tenant's own blank, and the box a design prints at.
import type { Placement, State } from '../state';

export type Box = { widthMm: number; heightMm: number };

/** A size Pixonaut can print at: finite and above zero. */
function printable(mm: number): boolean {
	return Number.isFinite(mm) && mm > 0;
}

/** Pixonaut refuses a placement it cannot size a print for as
 *  `unknown_placement`: a missing or non-positive bound, or a default
 *  larger than the maximum. */
export function usable(placement: Placement): boolean {
	return (
		[
			placement.maxWidthMm,
			placement.maxHeightMm,
			placement.defaultWidthMm,
			placement.defaultHeightMm,
		].every(printable) &&
		placement.defaultWidthMm <= placement.maxWidthMm &&
		placement.defaultHeightMm <= placement.maxHeightMm
	);
}

/** The placement `blankId` and `placementId` name on one of `tenantId`'s
 *  blanks, or undefined when the pair does not resolve to one Pixonaut can
 *  print on, which it refuses as `unknown_placement`. */
export function resolvePlacement(
	state: State,
	tenantId: string,
	blankId: string,
	placementId: string,
): Placement | undefined {
	const blank = state.blanks.get(blankId);
	const placement =
		blank?.tenantId === tenantId
			? blank.placements.find((p) => p.id === placementId)
			: undefined;
	return placement !== undefined && usable(placement) ? placement : undefined;
}

/** The template's ratio scaled to the largest box inside `box`, the size a
 *  proof naming the placement and no size prints at (Pixonaut's
 *  `fittedPrintSize`). */
export function fittedPrintSize(
	ratio: { w: number; h: number },
	box: Box,
): Box {
	const artwork = ratio.w / ratio.h;
	if (box.widthMm / box.heightMm >= artwork) {
		return {
			widthMm: Math.min(box.widthMm, box.heightMm * artwork),
			heightMm: box.heightMm,
		};
	}
	return {
		widthMm: box.widthMm,
		heightMm: Math.min(box.heightMm, box.widthMm / artwork),
	};
}

/** Where a placement prints a design that names no size of its own. */
export function defaultPrintSize(
	ratio: { w: number; h: number },
	placement: Placement,
): Box {
	return fittedPrintSize(ratio, {
		widthMm: placement.defaultWidthMm,
		heightMm: placement.defaultHeightMm,
	});
}

/** The sizes a template fits and misfits on a placement: none named, as a
 *  print is placed inside every placement of the fake's, whose area does
 *  not change with an option, so a template fits it at every size. */
export function sizeFits(): { fits: string[]; misfits: string[] } {
	return { fits: [], misfits: [] };
}
