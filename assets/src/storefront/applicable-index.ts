import { domReady } from '../shared/dom-ready';
import type { ApplicableAttributes } from './applicable-attributes';
import { hideInapplicableAttributes } from './applicable-form';

/** The global the server prints a product's attributes into, on the page
 *  of a product whose options depend on each other. */
export const APPLICABLE_GLOBAL = 'pixonautApplicableAttributes';

/** What the server prints: the rule's input, and the product it is of. */
type PrintedAttributes = ApplicableAttributes & { productId: number };

/** The classic form's attribute naming its product. */
const CLASSIC_PRODUCT_ATTRIBUTE = 'data-product_id';

domReady(() => {
	const printed = (globalThis as Record<string, unknown>)[APPLICABLE_GLOBAL] as
		| PrintedAttributes
		| undefined;
	if (printed === undefined) {
		return;
	}
	for (const form of document.querySelectorAll('form')) {
		// A classic form names its product; another product's form on the page is left alone.
		const product = form.getAttribute(CLASSIC_PRODUCT_ATTRIBUTE);
		if (product !== null && Number(product) !== printed.productId) {
			continue;
		}
		const holdsAttribute = Array.from(form.elements).some((field) =>
			printed.fields.includes((field as HTMLInputElement).name),
		);
		if (holdsAttribute) {
			hideInapplicableAttributes(form, printed);
		}
	}
});
