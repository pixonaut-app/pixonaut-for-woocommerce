import { NOT_APPLICABLE_ATTRIBUTE } from './applicable-form';
import { addressName, type QueryNames, readQueryNames } from './query-names';
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
			// An attribute that does not apply to the choice holds a value only
			// for WooCommerce to find the variation; the customer chose none.
			chosen[field.name] =
				field.closest(`[${NOT_APPLICABLE_ATTRIBUTE}]`) === null
					? chosenValue(form, field.name)
					: '';
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
 * fragment are kept. A global attribute goes by its short name
 * (`?storlek=50x70-cm`), which the server reads as WooCommerce's field.
 */
export function followAddress(form: HTMLFormElement, names?: QueryNames): void {
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
		for (const [field, value] of Object.entries(chosen)) {
			// Read once the page has printed them, which may be after the form mounts.
			const name = addressName(field, names ?? readQueryNames());
			if (name !== field) {
				// An address opened with WooCommerce's own name for it.
				address.searchParams.delete(field);
			}
			if (value === '') {
				address.searchParams.delete(name);
			} else {
				address.searchParams.set(name, value);
			}
		}
		view.history.replaceState(view.history.state, '', address);
	});
}
