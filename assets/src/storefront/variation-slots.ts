import { watchVariation } from './variations';

/** A place the server printed every variation's text in, stacked
 *  (`VariationSlots`). */
export const SLOT_SELECTOR = '[data-pixonaut-variation-slot]';

/** The variations, by id and space-separated, a text in a place is of. */
const VARIATIONS_ATTRIBUTE = 'data-pixonaut-variations';

/** Marks the text of the chosen variation. */
const CHOSEN_CLASS = 'is-chosen';

/** How a text that is not the chosen one keeps its room. */
const UNCHOSEN_VISIBILITY = 'hidden';

/** Shows, in `slot`, the text of variation `id` alone; none for an id no
 *  text is of, such as while no variation is chosen. The others keep
 *  their room, so the place keeps its height. */
export function showVariation(slot: Element, id: string): void {
	for (const item of slot.querySelectorAll<HTMLElement>(
		`[${VARIATIONS_ATTRIBUTE}]`,
	)) {
		const chosen =
			id !== '' &&
			(item.getAttribute(VARIATIONS_ATTRIBUTE) ?? '').split(' ').includes(id);
		item.classList.toggle(CHOSEN_CLASS, chosen);
		item.style.visibility = chosen ? '' : UNCHOSEN_VISIBILITY;
	}
}

/** Has each place in `root` follow the variation its add-to-cart form
 *  holds. */
export function followVariationSlots(root: ParentNode): void {
	for (const slot of root.querySelectorAll(SLOT_SELECTOR)) {
		const form = slot.closest('form');
		if (form !== null) {
			watchVariation(form, (id) => showVariation(slot, id));
		}
	}
}
