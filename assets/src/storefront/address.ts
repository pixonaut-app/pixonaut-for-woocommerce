import { chosenValue, followChoices } from './variations';

/** What the add-to-cart form's attribute fields are named by, and what
 *  WooCommerce reads from the page's address to choose a value. */
const ATTRIBUTE_PREFIX = 'attribute_';

/** The values the form's attribute fields hold, by field name; '' for an
 *  attribute with none chosen. */
function choices(form: HTMLFormElement): Record<string, string> {
	const chosen: Record<string, string> = {};
	for (const field of form.querySelectorAll<
		HTMLInputElement | HTMLSelectElement
	>('input, select')) {
		if (field.name.startsWith(ATTRIBUTE_PREFIX) && !(field.name in chosen)) {
			chosen[field.name] = chosenValue(form, field.name);
		}
	}
	return chosen;
}

/**
 * Keeps the page's address naming what the form's attribute fields hold,
 * so a link copied or shared opens the product on the same choice. The
 * address is replaced in place, adding nothing to the browser's history,
 * and only once the customer changed a choice: the address the page was
 * opened at stays as it is until then. Its other parameters and its
 * fragment are kept.
 */
export function followAddress(form: HTMLFormElement): void {
	const view = form.ownerDocument.defaultView;
	if (view === null) {
		return;
	}
	let last: string | null = null;
	followChoices(form, () => {
		const chosen = choices(form);
		const now = JSON.stringify(chosen);
		const opened = last === null;
		const same = now === last;
		last = now;
		if (opened || same) {
			return;
		}
		const address = new URL(view.location.href);
		for (const [name, value] of Object.entries(chosen)) {
			if (value === '') {
				address.searchParams.delete(name);
			} else {
				address.searchParams.set(name, value);
			}
		}
		view.history.replaceState(view.history.state, '', address);
	});
}
