import { chosenValue, type Variation, type Variations } from './variations';

/** What a side draws now: the options it is mounted with, or will mount
 *  with, and the print size it shows as an index into its mounted menu. */
export type DrawnSide = {
	templateId?: string;
	blankId?: string;
	placementId?: string;
	size?: number;
};

/** What the designer draws now. */
export type Drawn = {
	/** The variation it was last switched to; absent before any was. */
	variation?: Variation;
	/** The variant ref it shows; absent when it shows none. */
	variant?: string;
	sides: Record<string, DrawnSide>;
};

/**
 * The variation the designer draws while the form's choices name no single
 * one: a personalised variation `usable` allows that has every value the
 * form holds, and for the attributes not chosen yet keeps what is drawn.
 * The one that keeps most attribute values of the variation drawn comes
 * first, then the one that changes least of what the sides show, then the
 * variations' own order. Undefined while the form holds no value, once it
 * holds one for every attribute (the form then says which variation that
 * is, or that it sells none), and when no such variation has its values.
 */
export function variationDrawnFor(
	variations: Variations,
	form: HTMLFormElement,
	drawn: Drawn,
	usable: (id: string) => boolean,
): string | undefined {
	const kept = new Map<string, [attributes: number, shown: number]>();
	for (const [id, variation] of Object.entries(variations)) {
		if (variation.state !== 'personalised' || !usable(id)) {
			continue;
		}
		const held = Object.entries(variation.attributes).map(
			([name, value]) => [name, value, chosenValue(form, name)] as const,
		);
		// With every attribute chosen the form's own answer stands, and with
		// none there is nothing to apply. An "any" attribute takes whatever
		// the form holds.
		if (
			held.every(([, , chosen]) => chosen !== '') ||
			held.every(([, , chosen]) => chosen === '') ||
			held.some(
				([, value, chosen]) =>
					chosen !== '' && value !== '' && value !== chosen,
			)
		) {
			continue;
		}
		const before =
			drawn.variation === undefined ? {} : drawn.variation.attributes;
		kept.set(id, [
			held.filter(
				([name, value, chosen]) => chosen === '' && before[name] === value,
			).length,
			shownAlike(variation, drawn),
		]);
	}
	// Sorting is stable, so a tie keeps the variations' order.
	return [...kept.entries()].sort(
		([, first], [, second]) => second[0] - first[0] || second[1] - first[1],
	)[0]?.[0];
}

/** How much of what the designer draws the variation would leave as it is:
 *  its variant, and each side's template, blank, placement and size. */
function shownAlike(
	variation: Variation & { state: 'personalised' },
	drawn: Drawn,
): number {
	let alike =
		variation.variant !== undefined && variation.variant === drawn.variant
			? 1
			: 0;
	for (const [side, target] of Object.entries(variation.sides)) {
		const shown = drawn.sides[side];
		if (shown === undefined) {
			continue;
		}
		alike += [
			target.templateId === shown.templateId,
			target.blankId === shown.blankId,
			target.placementId === shown.placementId,
			shown.size !== undefined && target.printSizes.includes(shown.size),
		].filter(Boolean).length;
	}
	return alike;
}
