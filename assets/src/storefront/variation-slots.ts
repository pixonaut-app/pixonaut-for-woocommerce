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

/** Marks the element the chosen text is announced in. */
const ANNOUNCER_ATTRIBUTE = 'data-pixonaut-variation-announcer';

/** Seen by a screen reader alone: WordPress's `screen-reader-text`, inline
 *  so it holds on a theme without that class. */
const SCREEN_READER_ONLY =
	'position:absolute;width:1px;height:1px;margin:-1px;padding:0;overflow:hidden;clip-path:inset(50%);white-space:nowrap;border:0';

/** The live region the chosen text of `slot` is announced in, as
 *  WooCommerce's own place for a variation's text announced it: one, so
 *  the texts kept for their room are never read out. */
function announcer(slot: Element): HTMLElement {
	const found = slot.querySelector<HTMLElement>(`[${ANNOUNCER_ATTRIBUTE}]`);
	if (found !== null) {
		return found;
	}
	const element = slot.ownerDocument.createElement('span');
	element.setAttribute(ANNOUNCER_ATTRIBUTE, '');
	element.setAttribute('aria-live', 'polite');
	element.style.cssText = SCREEN_READER_ONLY;
	slot.append(element);
	return element;
}

/** Shows, in `slot`, the text of variation `id` alone, and announces it;
 *  none for an id no text is of, such as while no variation is chosen.
 *  The others keep their room, so the place keeps its height. */
export function showVariation(slot: Element, id: string): void {
	let said = '';
	for (const item of slot.querySelectorAll<HTMLElement>(
		`[${VARIATIONS_ATTRIBUTE}]`,
	)) {
		const chosen =
			id !== '' &&
			(item.getAttribute(VARIATIONS_ATTRIBUTE) ?? '').split(' ').includes(id);
		item.classList.toggle(CHOSEN_CLASS, chosen);
		item.style.visibility = chosen ? '' : UNCHOSEN_VISIBILITY;
		if (chosen) {
			said = item.textContent?.trim() ?? '';
		}
	}
	const live = announcer(slot);
	if (live.textContent !== said) {
		live.textContent = said;
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
