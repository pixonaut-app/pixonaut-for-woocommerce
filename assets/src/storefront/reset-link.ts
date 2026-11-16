import { OWN_STEP_ATTRIBUTE } from './own-step';
import type { ShopParts } from './shop-parts';

/** The classic form's link that clears every choice, which WooCommerce
 *  prints in the last attribute's row. */
export const RESET_SELECTOR = '.reset_variations';

/** A classic attribute row in a step of its own, whose heading names it:
 *  marked on the row's table the designer draws (`hostGroups()`) or on the
 *  printed form's slot holding it. */
const OWN_STEP_ROW_SELECTOR = `table.pixonaut-attribute[${OWN_STEP_ATTRIBUTE}], [${OWN_STEP_ATTRIBUTE}] > table.pixonaut-attribute`;

/** Where the Clear link stood in its attribute row, and the words it showed
 *  there, while it stands in the cart row (`liftResetLink()`). */
export type ResetLinkHome = {
	link: HTMLElement;
	home: Comment;
	text: string;
};

/**
 * Moves the classic form's Clear link to the start of the cart row once the
 * attribute it stands with has a step of its own. Left there, it would read
 * as clearing that attribute alone, while it clears every choice. Away from the rows it shows WooCommerce's longer name
 * for it, its `aria-label` ("Clear options"). WooCommerce's script finds the
 * link anywhere in the form, so it keeps working. Nothing happens while the
 * attribute shares its step with others, where the link ends their rows.
 */
export function liftResetLink(parts: ShopParts, form: HTMLFormElement): void {
	const link = form.querySelector<HTMLElement>(RESET_SELECTOR);
	if (link === null || link.closest(OWN_STEP_ROW_SELECTOR) === null) {
		return;
	}
	const home = form.ownerDocument.createComment('');
	link.before(home);
	parts.resetLink = { link, home, text: link.textContent ?? '' };
	const label = link.getAttribute('aria-label');
	if (label !== null && label !== '') {
		link.textContent = label;
	}
	parts.cartRow.prepend(link);
}

/** Puts the Clear link back in the attribute row it was taken from, with its
 *  words there (`liftResetLink()`). */
export function returnResetLink(parts: ShopParts): void {
	const lifted = parts.resetLink;
	if (lifted === undefined) {
		return;
	}
	delete parts.resetLink;
	const { link, home, text } = lifted;
	link.textContent = text;
	if (home.isConnected) {
		home.replaceWith(link);
	} else {
		// A row the page removed takes the mark with it: the link goes
		// after the form container, in the form, still clearing every
		// choice.
		parts.container.after(link);
	}
}
